#!/usr/bin/env bash
# Preserve executable commands; create launchers only for missing aliases or
# blocked sources, preferring the installation over temporary exec storage.
set -u
ROOT="$(cd -- "${BASH_SOURCE[0]%/*}/.." && pwd -P)" || exit 1
INHERITED_PATH="${PATH:-/usr/local/bin:/usr/bin:/bin}"
PATH="${FILEMANAGER_TERMINAL_BASE_PATH:-$INHERITED_PATH}"
source "$ROOT/scripts/runtime-exec.sh" || exit 1
for cli_dir in "$ROOT/node_modules/.bin" "$ROOT/.runtime/npm-global/bin" "$ROOT/.runtime/codex/bin" "$ROOT/.runtime/node/bin"; do
    case ":$PATH:" in *":$cli_dir:"*) ;; *) PATH="$PATH:$cli_dir" ;; esac
done
BASE_PATH="$PATH"

# Checking PATH alone is insufficient: Bash may list a file on a noexec mount.
native_binary() {
    local candidate
    while IFS= read -r candidate; do
        if [ -x "$candidate" ] && ! fm_noexec "$candidate"; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done < <(type -a -p -- "$1" 2>/dev/null || :)
    return 1
}

prepare_commands() (
    local state="$ROOT/.runtime" bin='' target name temp cli mode="${1:-}" needs_shell=0
    local -a cli_dirs=("$ROOT/.runtime/codex/bin" "$ROOT/.runtime/npm-global/bin" "$ROOT/node_modules/.bin") path_dirs=()
    local -A desired=() links=()
    # Do not execute arbitrary CLIs to test them: preserve their path and flags.
    for name in node npm npx php pcg composer; do
        native_binary "$name" >/dev/null && continue
        target=''
        case "$name" in
            node)
                target="$ROOT/.runtime/node/bin/node"
                [ -f "$target" ] || target="${FILEMANAGER_NODE_BINARY:-}" ;;
            npm|npx) target="$ROOT/.runtime/node/bin/$name" ;;
            php|pcg)
                target="$ROOT/pcg"
                [ -f "$target" ] || target="${HOME:-$ROOT}/.local/bin/pcg"
                if [ ! -f "$target" ] && [ -r "$state/supervision/php" ]; then
                    IFS= read -r target < "$state/supervision/php" || :
                fi ;;
            composer) target="$ROOT/composer" ;;
        esac
        [ -f "$target" ] || continue
        if [ -x "$target" ] && ! fm_noexec "$target"; then
            links[$name]="$target"
        else
            desired[$name]="$name"
            needs_shell=1
        fi
    done
    if [ "$mode" = --npm-shell ]; then
        IFS=: read -r -a path_dirs <<< "$INHERITED_PATH"
        # npm's nearest project and npx cache take precedence.
        for ((i=${#path_dirs[@]}-1; i>=0; i--)); do
            case "${path_dirs[i]}" in */node_modules/.bin) cli_dirs+=("${path_dirs[i]}") ;; esac
        done
    fi
    for cli in "${cli_dirs[@]}"; do
        for target in "$cli/"*; do
            [ -f "$target" ] || continue
            name=${target##*/}
            case "$name" in node|npm|npx|php|pcg|composer) continue ;; esac
            if [ "$mode" != --npm-shell ] && native_binary "$name" >/dev/null; then continue; fi
            if [ -x "$target" ] && ! fm_noexec "$target"; then
                unset 'desired[$name]'
            else
                desired[$name]="$target"
                needs_shell=1
            fi
        done
    done
    if [ "$needs_shell" = 1 ]; then desired[fm-npm-shell]=--npm-shell; fi
    # A normal terminal requires neither wrappers nor temporary directories.
    if [ "${#desired[@]}" = 0 ] && [ "${#links[@]}" = 0 ]; then return 0; fi
    mkdir -p "$state" || return 1
    [ ! -L "$state" ] && [ ! -L "$state/terminal-bin.lock" ] || return 1
    exec 9>"$state/terminal-bin.lock" || return 1
    flock -x 9 || return 1
    if [ "$mode" != --npm-shell ] && [ -f "$state/terminal-bin.path" ] && [ ! -L "$state/terminal-bin.path" ]; then
        IFS= read -r bin < "$state/terminal-bin.path" || bin=''
    fi
    # An old noexec session must not pin an unrestricted terminal to /dev/shm.
    if ! fm_noexec "$state" && [[ "$bin" != "$state/"* ]]; then bin=''; fi
    if [[ "$bin" != /* ]] || ! fm_private_file "$bin/.ready" || ! "$bin/.ready" 2>/dev/null; then
        bin=''
        if ! fm_noexec "$state"; then
            bin=$(FILEMANAGER_EXEC_DIR="$state" fm_exec_dir "$ROOT" 0) || bin=''
        fi
        [ -n "$bin" ] || bin=$(fm_exec_dir "$ROOT" 0) || return 1
        # Do not let runtime discovery mistake the node launcher for an ELF.
        bin="$bin/bin"
        mkdir -m 700 "$bin" || return 1
        (umask 077; printf '#!/bin/bash\nexit 0\n' > "$bin/.ready") && chmod 700 "$bin/.ready" || return 1
        if [ "$mode" != --npm-shell ]; then
            (umask 077; printf '%s\n' "$bin" > "$state/terminal-bin.path.new") || return 1
            mv -f -- "$state/terminal-bin.path.new" "$state/terminal-bin.path" || return 1
        fi
    fi
    write_shim() {
        local name="$1" target="$2"
        [[ "$name" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.+-]*$ ]] || return 0
        temp=$(mktemp "$bin/.shim.XXXXXXXX") || return 1
        {
            printf '#!/bin/bash\n'
            printf 'export FILEMANAGER_TERMINAL_BASE_PATH=%q\n' "$BASE_PATH"
            printf 'exec /bin/bash %q %q "$@"\n' "$ROOT/scripts/terminal-command.sh" "$target"
        } > "$temp" || { rm -f -- "$temp"; return 1; }
        chmod 700 "$temp" && mv -f -- "$temp" "$bin/$name"
        local status=$?
        [ "$status" -eq 0 ] || { rm -f -- "$temp"; return "$status"; }
    }
    # Packages installed globally in this profile and local project commands.
    # Resolve symlinks at invocation time so updates do not freeze old targets.
    for name in "${!desired[@]}"; do write_shim "$name" "${desired[$name]}" || return 1; done
    for name in "${!links[@]}"; do ln -sfn -- "${links[$name]}" "$bin/$name" || return 1; done
    for target in "$bin/"*; do
        name=${target##*/}
        [ -n "${desired[$name]:-${links[$name]:-}}" ] || rm -f -- "$target" || return 1
    done
    printf '%s\n' "$bin"
)

node_binary() { native_binary node || fm_node "$ROOT"; }
php_binary() { native_binary php || fm_php "$ROOT"; }
run_node() {
    local node
    node=$(node_binary) || { printf 'Node indisponível; execute bash installer.sh no projeto.\n' >&2; return 1; }
    # npm and its subprocesses must also find the working node/shims.
    PATH="$INHERITED_PATH"
    export PATH
    exec "$node" "$@"
}

if [ "${1:-}" = --prepare ]; then
    bin=$(prepare_commands) || exit 1
    printf '%s\n' "${bin:+$bin:}$BASE_PATH"
    exit 0
fi
command_name="${1:-}"; shift || exit 64
if [ -r "$ROOT/.runtime/terminal-bin.path" ]; then
    IFS= read -r FILEMANAGER_TERMINAL_BIN < "$ROOT/.runtime/terminal-bin.path" || :
fi
case "$command_name" in
    --npm-shell)
        shell_bin=$(prepare_commands --npm-shell) || exit 1
        PATH="${shell_bin:+$shell_bin:}$INHERITED_PATH" /bin/bash "$@"
        status=$?
        if [ -n "$shell_bin" ]; then
            rm -rf -- "$shell_bin"
            rmdir -- "${shell_bin%/*}"
        fi
        exit "$status" ;;
    node) run_node "$@" ;;
    npm|npx)
        cli_name=npm-cli.js
        [ "$command_name" != npx ] || cli_name=npx-cli.js
        system_npm=$(type -P npm || :)
        system_npm=$(readlink -f -- "$system_npm" 2>/dev/null || :)
        for cli in "$ROOT/.runtime/node/lib/node_modules/npm/bin/$cli_name" \
            "${system_npm%/*}/$cli_name" /usr/share/nodejs/npm/bin/"$cli_name" /usr/local/lib/node_modules/npm/bin/"$cli_name"; do
            if [ -f "$cli" ] && [ -f "${cli%/*}/../lib/cli.js" ]; then
                node=$(node_binary) || exit 1
                PATH="$INHERITED_PATH" "$node" "$cli" "$@"
                status=$?
                # Make new package commands usable even in `npm install && cli`.
                prepare_commands >/dev/null || printf 'Não foi possível atualizar os comandos do terminal.\n' >&2
                exit "$status"
            fi
        done
        printf 'Estrutura do npm ausente/incompleta; execute bash installer.sh no projeto.\n' >&2
        exit 1 ;;
    php|pcg|composer)
        if [ "$command_name" = pcg ]; then php=$(fm_php "$ROOT") || exit 1
        else php=$(php_binary) || exit 1; fi
        if [ "$command_name" = composer ]; then exec "$php" "$ROOT/composer" "$@"; fi
        exec "$php" "$@" ;;
    /*)
        target=$(readlink -f -- "$command_name") || exit 1
        [ -f "$target" ] || { printf 'Comando removido: %s\n' "$command_name" >&2; exit 127; }
        IFS= read -r header < "$target" || :
        case "$header" in
            '#!'*node*) run_node "$target" "$@" ;;
            '#!'*bash*) exec /bin/bash "$target" "$@" ;;
            '#!'*'/sh') exec /bin/sh "$target" "$@" ;;
            '#!'*php*) php=$(php_binary) || exit 1; exec "$php" "$target" "$@" ;;
            *) exec "$target" "$@" ;;
        esac ;;
    *) printf 'Comando desconhecido: %s\n' "$command_name" >&2; exit 64 ;;
esac
