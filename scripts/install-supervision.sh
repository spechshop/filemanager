#!/usr/bin/env bash
# Sourced by installer.sh after PHP and the local controller are available.
# Keep all lifecycle operations in filemanagerctl, including migrations.

supervision_as_owner() {
    # Migration/system enable previously ran via run_priv. Preserve that ability
    # when an administrator repairs a system unit; the controller itself delegates
    # user-owned watchdog state back to its owner. Always interpret via Bash.
    case "${1:-}" in
        __migrate-stop|__install-enable)
            if [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; then
                run_priv /bin/bash "$CONTROL_SCRIPT" "$@"
                return $?
            fi
            ;;
    esac
    if [ "$(id -un)" = "$FILEMANAGER_USER" ]; then
        /bin/bash "$CONTROL_SCRIPT" "$@"
    elif [ "$IS_ROOT" -eq 1 ]; then
        runuser -u "$FILEMANAGER_USER" -- /bin/bash "$CONTROL_SCRIPT" "$@"
    elif [ -n "$SUDO" ]; then
        $SUDO -u "$FILEMANAGER_USER" /bin/bash "$CONTROL_SCRIPT" "$@"
    else
        return 1
    fi
}

# Inspect the service account's manager, including upgrades performed by root.
supervision_user_systemctl() {
    local runtime
    runtime="/run/user/$(id -u "$FILEMANAGER_USER")"
    if [ "$(id -un)" = "$FILEMANAGER_USER" ]; then
        if [ -O "$runtime" ] && [ -S "$runtime/bus" ]; then
            XDG_RUNTIME_DIR="$runtime" DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user "$@"
        else
            systemctl --user "$@"
        fi
    elif [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; then
        run_priv runuser -u "$FILEMANAGER_USER" -- env XDG_RUNTIME_DIR="$runtime" \
            DBUS_SESSION_BUS_ADDRESS="unix:path=$runtime/bus" systemctl --user "$@"
    else
        return 1
    fi
}

# systemd quoted values: preserve whitespace, %, $, quotes and backslashes.
supervision_unit_quote() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//%/%%}"
    if [ "${2:-}" = exec ]; then value="${value//\$/\$\$}"; fi
    printf '"%s"' "$value"
}

supervision_write_unit() {
    local kind="$1" destination="$2" tmp target
    tmp="$(mktemp)" || return 1
    target=multi-user.target
    [ "$kind" = user ] && target=default.target
    {
        printf '[Unit]\nDescription=FileManager Server\nAfter=network.target\nStartLimitIntervalSec=0\n\n'
        printf '[Service]\nType=simple\nWorkingDirectory=%s\n' "${CURRENT_DIR//%/%%}"
        printf 'ExecStart=/bin/bash %s run %s %s\n' "$(supervision_unit_quote "$CURRENT_DIR/scripts/runtime-exec.sh" exec)" "$(supervision_unit_quote "$CURRENT_DIR" exec)" "$(supervision_unit_quote "$CURRENT_DIR/server.php" exec)"
        printf 'Restart=always\nRestartSec=2\nKillMode=control-group\nTimeoutStopSec=15\n'
        [ "$kind" = system ] && printf 'User=%s\n' "$FILEMANAGER_USER"
        printf '\n[Install]\nWantedBy=%s\n' "$target"
    } > "$tmp"
    if [ "$kind" = system ]; then
        run_priv install -m 0644 "$tmp" "$destination"
    else
        install -m 0644 "$tmp" "$destination"
    fi
    local result=$?
    rm -f -- "$tmp"
    return "$result"
}

configure_supervision() {
    local state_dir="$CURRENT_DIR/.runtime/supervision" instance_id service kind candidate working_dir
    local system_service="" user_unit_dir previous_autostart previous_stop
    previous_autostart="$(cat "$state_dir/autostart" 2>/dev/null)"
    previous_stop="$(cat "$state_dir/stopped-boot" 2>/dev/null)"
    if [ "${1:-}" = --start-after-repair ] && [ "$previous_autostart" != disabled ]; then
        previous_stop=""
    fi
    instance_id="$(printf '%s' "$CURRENT_DIR" | sha256sum | cut -c1-16)"
    service="filemanager-$instance_id.service"

    # Never overwrite a fixed-name legacy unit belonging to another checkout.
    for candidate in filemanager.service "$service"; do
        working_dir="$(systemctl show -p WorkingDirectory --value "$candidate" 2>/dev/null)"
        working_dir="$(readlink -f -- "$working_dir" 2>/dev/null)"
        if [ "$working_dir" = "$CURRENT_DIR" ]; then
            run_priv systemctl disable --now "$candidate" || return 1
            system_service="$candidate"
        fi
        working_dir="$(supervision_user_systemctl show -p WorkingDirectory --value "$candidate" 2>/dev/null)"
        working_dir="$(readlink -f -- "$working_dir" 2>/dev/null)"
        if [ "$working_dir" = "$CURRENT_DIR" ]; then
            supervision_user_systemctl disable --now "$candidate" || return 1
        fi
    done

    # Quiesce every previous mechanism before changing the backend. A failure
    # here must not create another supervisor alongside one we cannot stop.
    if [ -n "$system_service" ]; then
        service="$system_service"
    fi
    if ! mkdir -p "$state_dir"; then return 1; fi
    if [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; then
        run_priv chown "$FILEMANAGER_USER" "$CURRENT_DIR/.runtime" || return 1
        run_priv chown -R "$FILEMANAGER_USER" "$state_dir" || return 1
        for candidate in "$CURRENT_DIR/filemanager.log" "$CURRENT_DIR/filemanager.log.1"; do
            [ ! -e "$candidate" ] || run_priv chown "$FILEMANAGER_USER" "$candidate" || return 1
        done
    fi
    chmod 700 "$state_dir" 2>/dev/null
    # stop records this boot's administrative intent before removing old cron.
    supervision_as_owner __migrate-stop || return 1
    supervision_as_owner __configure "$PHP_BIN" "$service" background || return 1
    # Remove only this installation's old cron launchers for the service account.
    supervision_as_owner __migrate-stop || return 1

    # A: system manager. Failed candidates must be disabled before fallback.
    if command -v systemctl >/dev/null 2>&1 && { [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; }; then
        if supervision_write_unit system "/etc/systemd/system/$service" \
            && run_priv systemctl daemon-reload \
            && supervision_as_owner __configure "$PHP_BIN" "$service" system \
            && supervision_as_owner __install-enable "$previous_autostart" "$previous_stop"; then
            return 0
        fi
        run_priv systemctl disable --now "$service" || {
            # Without a loaded unit there is no competing system supervisor.
            [ "$(systemctl show -p LoadState --value "$service" 2>/dev/null)" != loaded ] || return 1
        }
        warn "systemd de sistema indisponível; tentando alternativas do usuário."
    fi

    # B: only select user systemd when it can run without a login session.
    # Do not configure root's user manager for a different service account.
    if [ "$(id -un)" = "$FILEMANAGER_USER" ] \
        && supervision_user_systemctl show-environment >/dev/null 2>&1; then
        if [ "$(loginctl show-user "$FILEMANAGER_USER" -p Linger --value 2>/dev/null)" != yes ]; then
            if [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; then
                run_priv loginctl enable-linger "$FILEMANAGER_USER" 2>/dev/null
            else
                loginctl --no-ask-password enable-linger "$FILEMANAGER_USER" 2>/dev/null
            fi
        fi
        if [ "$(loginctl show-user "$FILEMANAGER_USER" -p Linger --value 2>/dev/null)" = yes ]; then
            kind=user
            user_unit_dir="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
            if mkdir -p "$user_unit_dir" \
                && supervision_write_unit "$kind" "$user_unit_dir/$service" \
                && supervision_user_systemctl daemon-reload \
                && supervision_as_owner __configure "$PHP_BIN" "$service" user \
                && supervision_as_owner __install-enable "$previous_autostart" "$previous_stop"; then
                return 0
            fi
            supervision_user_systemctl disable --now "$service" || {
                [ "$(supervision_user_systemctl show -p LoadState --value "$service" 2>/dev/null)" != loaded ] || return 1
            }
        else
            warn "Linger indisponível; usando cron para iniciar sem sessão de login."
        fi
    fi

    # C: @reboot plus a minute tick recovering a killed watchdog. PHP recovery
    # itself is handled by the blocking shell wait + 2s delay, not by cron.
    supervision_as_owner __configure "$PHP_BIN" "$service" watchdog || return 1
    if supervision_as_owner __install-enable "$previous_autostart" "$previous_stop"; then
        return 0
    fi

    # D: detached watchdog for this boot when neither persistent backend works.
    warn "Sem autostart persistente: usando supervisão em background neste boot."
    supervision_as_owner __configure "$PHP_BIN" "$service" background || return 1
    supervision_as_owner __install-background "$previous_autostart" "$previous_stop"
}

configure_supervision "${1:-}"
