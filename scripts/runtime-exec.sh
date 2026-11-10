#!/usr/bin/env bash
# Shared Linux execution policy. Sources remain persistent; caches are disposable.
fm_noexec() {
    local options path
    if command -v findmnt >/dev/null 2>&1; then
        options=$(findmnt -n -o OPTIONS -T "$1" 2>/dev/null) || return 1
    elif [ -r /proc/self/mountinfo ]; then
        path=$(readlink -f -- "$1") || return 1
        options=$(awk -v path="$path" '
            { mount=$5; gsub(/\\040/, " ", mount); gsub(/\\011/, "\t", mount);
              if ((mount == "/" || path == mount || index(path, mount "/") == 1) && length(mount) > longest) {
                  longest=length(mount); options=$6;
              }
            } END {print options}' /proc/self/mountinfo)
    else
        return 1
    fi
    [[ ",$options," = *,noexec,* ]]
}

fm_exec_dir() {
    local installation="$1" required="${2:-0}" base dir available probe
    # An explicit directory is authoritative (also useful for hosts with policies).
    local -a candidates
    if [ -n "${FILEMANAGER_EXEC_DIR:-}" ]; then candidates=("$FILEMANAGER_EXEC_DIR")
    else candidates=("${TMPDIR:-/tmp}" /dev/shm "${XDG_RUNTIME_DIR:-/nonexistent}" "$installation/.runtime/exec"); fi
    for base in "${candidates[@]}"; do
        [ -d "$base" ] && [ -w "$base" ] || continue
        fm_noexec "$base" && { printf 'Filesystem noexec: %s\n' "$base" >&2; continue; }
        available=$(df -Pk -- "$base" 2>/dev/null | awk 'END {print $4}')
        [[ "$available" =~ ^[0-9]+$ ]] && [ "$((available * 1024))" -ge "$((required + 1048576))" ] || continue
        dir=$(mktemp -d "$base/filemanager-$(id -u)-$(printf %s "$installation" | cksum | cut -d' ' -f1).XXXXXXXX") || continue
        chmod 700 "$dir" || { rmdir "$dir"; continue; }
        # Execute an actual ELF, rather than interpreting a script on noexec.
        probe=$(command -v true)
        [[ "$probe" = /* ]] || probe=/bin/true
        if cp -- "$probe" "$dir/true" && chmod 700 "$dir/true" && "$dir/true"; then
            rm -f -- "$dir/true"
            printf '%s\n' "$dir"; return 0
        fi
        rm -f -- "$dir/true"; rmdir "$dir"
    done
    printf '%s\n' 'Sem diretório gravável e executável com espaço suficiente. Solicite um local autorizado ao provedor (FILEMANAGER_EXEC_DIR).' >&2
    return 1
}

fm_prepare_binary() {
    local source="$1" installation="$2" size dir digest cached cachefile
    shift 2
    [ -f "$source" ] && [ -r "$source" ] || { printf 'Binário inexistente/inacessível: %s\n' "$source" >&2; return 1; }
    if "$source" "$@" >/dev/null 2>&1; then printf '%s\n' "$source"; return 0; fi
    if fm_noexec "$source"; then printf 'Execução bloqueada por noexec: %s\n' "$source" >&2
    elif [ ! -x "$source" ]; then printf 'Sem permissão de execução: %s\n' "$source" >&2
    else printf 'Binário não executa ou é incompatível: %s (%s)\n' "$source" "$(uname -m)" >&2; return 1; fi
    digest=$(sha256sum "$source") || return 1
    cachefile="$installation/.runtime/exec-${digest%% *}.path"
    if [ -f "$cachefile" ] && [ ! -L "$cachefile" ]; then
        IFS= read -r cached < "$cachefile"
        if [ -O "${cached%/*}" ] && [ ! -L "${cached%/*}" ] && [ -O "$cached" ] && [ ! -L "$cached" ] \
            && [ "${digest%% *}" = "$(sha256sum "$cached" 2>/dev/null | cut -d' ' -f1)" ] \
            && "$cached" "$@" >/dev/null 2>&1; then printf '%s\n' "$cached"; return 0; fi
    fi
    size=$(stat -c %s -- "$source") || return 1
    dir=$(fm_exec_dir "$installation" "$size") || return 1
    digest=$(sha256sum "$source") || return 1
    if cp -- "$source" "$dir/${source##*/}" && chmod 700 "$dir/${source##*/}" \
        && [ "${digest%% *}" = "$(sha256sum "$dir/${source##*/}" | cut -d' ' -f1)" ] \
        && "$dir/${source##*/}" "$@" >/dev/null 2>&1; then
        if mkdir -p "$installation/.runtime"; then
            (umask 077; printf '%s\n' "$dir/${source##*/}" > "$cachefile.$$") && mv -f -- "$cachefile.$$" "$cachefile"
        fi
        printf '%s\n' "$dir/${source##*/}"; return 0
    fi
    rm -f -- "$dir/${source##*/}"; rmdir "$dir"
    printf 'Falha de integridade, arquitetura, bibliotecas ou execução: %s\n' "$source" >&2
    return 1
}

fm_php() {
    local root="$1" candidate prepared configured
    configured=$(cat "$root/.runtime/supervision/php" 2>/dev/null)
    for candidate in "$root/pcg" "$configured" "$(command -v pcg)" "$(command -v php)"; do
        [ -n "$candidate" ] && [ -f "$candidate" ] || continue
        prepared=$(fm_prepare_binary "$candidate" "$root" -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("swoole") && extension_loaded("zip") && extension_loaded("sockets") && extension_loaded("pcntl") && extension_loaded("posix") ? 0 : 1);') || continue
        printf '%s\n' "$prepared"; return 0
    done
    printf '%s\n' 'PHP >= 8.3 com Swoole, zip, sockets, pcntl e posix não disponível. Execute o instalador; confira arquitetura e extensões.' >&2
    return 1
}

fm_node() {
    local root="$1" candidate prepared
    for candidate in "$root/.runtime/node/bin/node" "$(command -v node)"; do
        [ -n "$candidate" ] && [ -f "$candidate" ] || continue
        prepared=$(fm_prepare_binary "$candidate" "$root" -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)') || continue
        printf '%s\n' "$prepared"; return 0
    done
    return 1
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    case "${1:-}" in
        php) fm_php "$2" ;;
        node) fm_node "$2" ;;
        run) root="$2"; shift 2; runtime=$(fm_php "$root") || exit 1; exec "$runtime" "$@" ;;
        *) exit 64 ;;
    esac
fi
