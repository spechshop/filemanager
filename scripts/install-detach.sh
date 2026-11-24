#!/usr/bin/env bash
# Executed before installation changes the running service.
# A transient systemd unit lives outside filemanager.service's cgroup.

fm_installer_service_context() {
    local checkout="$1" process_cgroup="$2" unit backend workdir service_cgroup
    local instance
    instance="$(printf '%s' "$checkout" | sha256sum | cut -c1-16)" || return 1
    local -a candidates=("filemanager.service" "filemanager-$instance.service")
    if [ -r "$checkout/.runtime/supervision/unit" ]; then
        unit="$(head -n 1 "$checkout/.runtime/supervision/unit")"
        if [[ "$unit" =~ ^filemanager(-[a-f0-9]{16})?\.service$ ]]; then
            candidates=("$unit" "${candidates[@]}")
        fi
    fi
    for backend in system user; do
        for unit in "${candidates[@]}"; do
            if [ "$backend" = system ]; then
                workdir="$(systemctl show "$unit" -p WorkingDirectory --value 2>/dev/null)" || continue
                service_cgroup="$(systemctl show "$unit" -p ControlGroup --value 2>/dev/null)" || continue
            else
                workdir="$(systemctl --user show "$unit" -p WorkingDirectory --value 2>/dev/null)" || continue
                service_cgroup="$(systemctl --user show "$unit" -p ControlGroup --value 2>/dev/null)" || continue
            fi
            [ -n "$service_cgroup" ] && [ "$service_cgroup" != / ] || continue
            [ "$(readlink -f -- "$workdir" 2>/dev/null)" = "$checkout" ] || continue
            case "$process_cgroup" in
                "$service_cgroup"|"$service_cgroup"/*)
                    printf '%s\n' "$backend"
                    return 0
                    ;;
            esac
        done
    done
    return 1
}

fm_installer_process_cgroup() {
    local _hierarchy _controllers group
    while IFS=: read -r _hierarchy _controllers group; do
        [ "$_hierarchy" = 0 ] && { printf '%s\n' "$group"; return 0; }
    done < "/proc/${BASHPID}/cgroup"
    return 1
}

# Return 2 when not inside our own service, 0 when handed off, 1 on error.
fm_installer_dispatch() {
    local installer="$1" process_cgroup="$2"
    shift 2
    [ "${FILEMANAGER_INSTALL_DETACHED:-}" != 1 ] || return 2
    local checkout backend instance unit logdir logpath command user_runtime
    checkout="$(cd "$(dirname "$installer")" && pwd -P)" || return 1
    # Match the controller's recovery of the user bus when called by a PTY
    # without XDG/DBus environment exported by a login manager.
    user_runtime="/run/user/$(id -u)"
    if [ -O "$user_runtime" ] && [ -S "$user_runtime/bus" ]; then
        export XDG_RUNTIME_DIR="$user_runtime"
        export DBUS_SESSION_BUS_ADDRESS="unix:path=$user_runtime/bus"
    fi
    backend="$(fm_installer_service_context "$checkout" "$process_cgroup")" || return 2

    logdir="$checkout/.runtime/install"
    if ! (umask 077; mkdir -p -- "$logdir" && [ -w "$logdir" ]); then
        printf 'Erro: sem acesso ao diretório privado de logs do instalador.\n' >&2
        return 1
    fi
    instance="$(printf '%s' "$checkout" | sha256sum | cut -c1-16)" || return 1
    unit="filemanager-install-$instance-${BASHPID}"
    logpath="$logdir/$unit.log"

    # Keep the installing account, even when systemd-run requires sudo.
    local -a run=(systemd-run)
    if [ "$backend" = system ]; then
        if [ "$(id -u)" != 0 ]; then
            command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 || {
                printf 'Erro: sem permissão para executar a instalação fora do serviço systemd.\n' >&2
                return 1
            }
            run=(sudo -n systemd-run)
        fi
        run+=(--uid="$(id -un)")
    else
        run+=(--user)
    fi
    run+=(--quiet --collect "--unit=$unit" "--working-directory=$checkout"
          --setenv=FILEMANAGER_INSTALL_DETACHED=1)
    if [ -n "${SUDO_USER:-}" ]; then run+=("--setenv=SUDO_USER=$SUDO_USER"); fi
    if [ -n "${FILEMANAGER_SERVICE_USER:-}" ]; then
        run+=("--setenv=FILEMANAGER_SERVICE_USER=$FILEMANAGER_SERVICE_USER")
    fi

    # This unit is NOT in filemanager.service's cgroup. Its log and lock
    # remain accessible if the web terminal disappears during restart.
    command='umask 077
log=$1; directory=$2; shift 2
exec >> "$log" 2>&1
exec 9>"$directory/install.lock"
if ! flock -n 9; then
    printf "Outra instalação já está em andamento.\n"
    exit 75
fi
printf "Instalação independente iniciada em %s\n" "$(date -Is)"
/bin/bash "$@"
result=$?
printf "Instalação independente finalizada (exit=%s) em %s\n" "$result" "$(date -Is)"
exit "$result"'
    if ! "${run[@]}" /bin/bash -c "$command" filemanager-installer "$logpath" "$logdir" "$installer" "$@"; then
        printf 'Erro: não foi possível agendar a instalação externa; serviço preservado.\n' >&2
        return 1
    fi
    printf 'Instalação agendada fora do FileManager.\n'
    printf 'Unidade: %s\nLog: %s\n' "$unit.service" "$logpath"
    printf 'A conexão do terminal pode cair durante o reinício, mas a instalação continuará.\n'
    return 0
}
