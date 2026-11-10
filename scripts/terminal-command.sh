#!/usr/bin/env bash
# Executable shims live on an exec mount; packages stay in their original tree.
set -u
ROOT="$(cd -- "${BASH_SOURCE[0]%/*}/.." && pwd -P)" || exit 1
INHERITED_PATH="${PATH:-/usr/local/bin:/usr/bin:/bin}"
PATH="${FILEMANAGER_TERMINAL_BASE_PATH:-/usr/local/bin:/usr/bin:/bin}"
source "$ROOT/scripts/runtime-exec.sh" || exit 1
BASE_PATH="$PATH"

prepare_commands() (
    local state="$ROOT/.runtime" bin='' target name temp cli mode="${1:-}"
    local -a cli_dirs=("$ROOT/.runtime/codex/bin" "$ROOT/.runtime/npm-global/bin" "$ROOT/node_modules/.bin") path_dirs=()
    local -A desired=()
    mkdir -p "$state" || return 1
    [ ! -L "$state" ] && [ ! -L "$state/terminal-bin.lock" ] || return 1
    exec 9>"$state/terminal-bin.lock" || return 1
    flock -x 9 || return 1
    if [ "$mode" != --npm-shell ] && [ -f "$state/terminal-bin.path" ] && [ ! -L "$state/terminal-bin.path" ]; then
        IFS= read -r bin < "$state/terminal-bin.path" || bin=''
    fi
    if [[ "$bin" != /* ]] || [ ! -x "$bin/node" ] || ! fm_private_file "$bin/node"; then
        bin=$(fm_exec_dir "$ROOT" 0) || return 1
        # Do not let runtime discovery mistake the node launcher for an ELF.
        bin="$bin/bin"
        mkdir -m 700 "$bin" || return 1
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
        desired[$name]=1
    }
    # Packages installed globally in this profile and local project commands.
    # Resolve symlinks at invocation time so updates do not freeze old targets.
    if [ "$mode" = --npm-shell ]; then
        IFS=: read -r -a path_dirs <<< "$INHERITED_PATH"
        # Preserve npm's nearest-project precedence, including npm exec/npx's
        # cache .bin directories, while leaving JS beside its dependencies.
        for ((i=${#path_dirs[@]}-1; i>=0; i--)); do
            case "${path_dirs[i]}" in */node_modules/.bin) cli_dirs+=("${path_dirs[i]}") ;; esac
        done
    fi
    for cli in "${cli_dirs[@]}"; do
        for target in "$cli/"*; do
            [ -f "$target" ] || continue
            name=${target##*/}
            case "$name" in node|npm|npx|php|pcg|composer) continue ;; esac
            write_shim "$name" "$target" || return 1
        done
    done
    for name in node npm npx php pcg composer; do write_shim "$name" "$name" || return 1; done
    write_shim fm-npm-shell --npm-shell || return 1
    for target in "$bin/"*; do
        name=${target##*/}
        [ -n "${desired[$name]:-}" ] || rm -f -- "$target" || return 1
    done
    printf '%s\n' "$bin"
)

node_binary() { fm_node "$ROOT"; }
run_node() {
    local node
    node=$(node_binary) || { printf 'Node indisponível; execute bash installer.sh no projeto.\n' >&2; return 1; }
    # npm and its subprocesses must also find the working node/shims.
    PATH="$INHERITED_PATH"
    export PATH
    exec "$node" "$@"
}

if [ "${1:-}" = --prepare ]; then prepare_commands; exit $?; fi
command_name="${1:-}"; shift || exit 64
if [ -r "$ROOT/.runtime/terminal-bin.path" ]; then
    IFS= read -r FILEMANAGER_TERMINAL_BIN < "$ROOT/.runtime/terminal-bin.path" || :
fi
case "$command_name" in
    --npm-shell)
        shell_bin=$(prepare_commands --npm-shell) || exit 1
        PATH="$shell_bin:$INHERITED_PATH" /bin/bash "$@"
        status=$?
        rm -rf -- "$shell_bin"
        rmdir -- "${shell_bin%/*}"
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
        php=$(fm_php "$ROOT") || exit 1
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
            '#!'*php*) php=$(fm_php "$ROOT") || exit 1; exec "$php" "$target" "$@" ;;
            *) exec "$target" "$@" ;;
        esac ;;
    *) printf 'Comando desconhecido: %s\n' "$command_name" >&2; exit 64 ;;
esac
