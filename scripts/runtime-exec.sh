#!/usr/bin/env bash
# Shared Linux execution policy. Persistent originals are never replaced by caches.
# Web terminals and cron may inherit an incomplete PATH.
for fm_system_bin in /usr/local/bin /usr/bin /bin /usr/local/sbin /usr/sbin /sbin; do
    case ":${PATH:-}:" in *":$fm_system_bin:"*) ;; *) PATH="${PATH:+$PATH:}$fm_system_bin" ;; esac
done
unset fm_system_bin
export PATH

fm_noexec() {
    local options='' path
    if command -v findmnt >/dev/null 2>&1; then
        options=$(findmnt -n -o OPTIONS -T "$1" 2>/dev/null) || options=''
    fi
    if [ -z "$options" ] && [ -r /proc/self/mountinfo ]; then
        path=$(readlink -f -- "$1" 2>/dev/null) || path="$1"
        options=$(awk -v path="$path" '
            { mount=$5; gsub(/\\040/, " ", mount); gsub(/\\011/, "\t", mount);
              if ((mount == "/" || path == mount || index(path, mount "/") == 1) && length(mount) > longest) {
                  longest=length(mount); options=$6;
              }
            } END {print options}' /proc/self/mountinfo 2>/dev/null) || options=''
    fi
    [[ ",$options," = *,noexec,* ]]
}

fm_candidate_dirs() {
    local root="$1" base uid="${EUID:-$(id -u)}"
    if [ -n "${FILEMANAGER_EXEC_DIR:-}" ]; then
        printf '%s\n' "$FILEMANAGER_EXEC_DIR"
        return
    fi
    for base in "${TMPDIR:-/tmp}" /dev/shm "${XDG_RUNTIME_DIR:-/run/user/$uid}" "/run/user/$uid" /var/tmp \
        "$root/.runtime/exec" "${HOME:-$root}/.cache/filemanager/exec"; do
        printf '%s\n' "$base"
    done
}

# Installation diagnostics are kept on stderr so command substitutions return
# only the selected executable path.
fm_strict_diagnose() {
    local dir
    printf 'Modo estrito detectado: procurando armazenamento gravável e execução ELF.\n' >&2
    for dir in /var/tmp /dev/shm "/run/user/$(id -u)" /opt /usr/local/bin; do
        [ -d "$dir" ] || continue
        printf '=== %s ===\n' "$dir" >&2
        findmnt -T "$dir" -no TARGET,OPTIONS >&2 2>/dev/null || :
        if [ -w "$dir" ]; then printf 'GRAVAVEL\n' >&2
        else printf 'SEM PERMISSAO DE ESCRITA\n' >&2; fi
    done
}

fm_strict_candidates() {
    local root="$1" dir available
    local -A seen=()
    # Rank writable locations by measured capacity. fm_exec_dir still tests a
    # real ELF: writable does not mean executable, regardless of mount hints.
    while IFS= read -r dir; do
        [ -n "$dir" ] && [ -z "${seen[$dir]:-}" ] || continue
        seen[$dir]=1
        [ -d "$dir" ] && [ -w "$dir" ] || continue
        available=$(fm_available_bytes "$dir") || continue
        printf '%s\t%s\n' "$available" "$dir"
    done < <(
        if [ -n "${FILEMANAGER_EXEC_DIR:-}" ]; then printf '%s\n' "$FILEMANAGER_EXEC_DIR"
        else
            printf '%s\n' /var/tmp /dev/shm "/run/user/$(id -u)" /opt /usr/local/bin
            fm_candidate_dirs "$root"
        fi
    ) | sort -rn -k1,1 | cut -f2-
}

fm_sha256() {
    local digest
    if digest=$(sha256sum -- "$1" 2>/dev/null); then digest=${digest%% *}
    elif digest=$(shasum -a 256 -- "$1" 2>/dev/null); then digest=${digest%% *}
    elif digest=$(openssl dgst -sha256 -r "$1" 2>/dev/null); then digest=${digest%% *}
    else printf 'SHA-256 indisponível: sha256sum, shasum e openssl falharam.\n' >&2; return 1; fi
    [[ "$digest" =~ ^[a-fA-F0-9]{64}$ ]] || return 1
    printf '%s\n' "${digest,,}"
}

# Refuse files other users can replace/modify before probing them. A digest,
# when available, is checked BEFORE execution. Do not follow cache symlinks.
fm_private_file() {
    local file="$1" metadata owner mode permissions links rest
    [ -f "$file" ] && [ ! -L "$file" ] && [ -r "$file" ] || return 1
    if metadata=$(stat -c '%u %a' -- "$file" 2>/dev/null); then
        read -r owner mode <<< "$metadata"
        [[ "$owner" =~ ^[0-9]+$ && "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
        [ "$owner" = "$EUID" ] || [ "$owner" = 0 ] || return 1
        (( (8#$mode & 022) == 0 )) || return 1
    else
        metadata=$(LC_ALL=C ls -ldn -- "$file" 2>/dev/null) || return 1
        read -r permissions links owner rest <<< "$metadata"
        [ "$owner" = "$EUID" ] || [ "$owner" = 0 ] || return 1
        [[ "$permissions" = -* && "${permissions:5:1}" != w && "${permissions:8:1}" != w ]] || return 1
    fi
    # Sticky /tmp and /dev/shm permit unrelated users to create their own files,
    # but not to replace this user's file. Reject unsafe writable parent dirs.
    local parent="${file%/*}"
    while [ -n "$parent" ] && [ "$parent" != / ] && [ "$parent" != . ]; do
        [ -L "$parent" ] && return 1
        metadata=$(LC_ALL=C ls -ldn -- "$parent" 2>/dev/null) || return 1
        read -r permissions links owner rest <<< "$metadata"
        if [[ "${permissions:5:1}" = w || "${permissions:8:1}" = w ]]; then
            [[ "${permissions:9:1}" = t || "${permissions:9:1}" = T ]] || return 1
        fi
        parent="${parent%/*}"
    done
}

fm_available_bytes() {
    local base="$1" result blocks blocksize php magic
    if result=$(LC_ALL=C df -Pk -- "$base" 2>/dev/null); then
        result=$(awk 'END {print $4}' <<< "$result")
        if [[ "$result" =~ ^[0-9]+$ ]]; then printf '%s\n' "$((result * 1024))"; return; fi
    fi
    printf 'Não foi possível medir espaço com df em %s; tentando stat/statvfs.\n' "$base" >&2
    if result=$(stat -f -c '%a %S' -- "$base" 2>/dev/null); then
        read -r blocks blocksize <<< "$result"
        if [[ "$blocks" =~ ^[0-9]+$ && "$blocksize" =~ ^[0-9]+$ ]]; then
            printf '%s\n' "$((blocks * blocksize))"; return
        fi
    fi
    if result=$(python3 -c 'import os,sys; s=os.statvfs(sys.argv[1]); print(s.f_bavail*s.f_frsize)' "$base" 2>/dev/null) \
        && [[ "$result" =~ ^[0-9]+$ ]]; then printf '%s\n' "$result"; return; fi
    printf 'Espaço desconhecido em %s: df, stat e statvfs indisponíveis. Não é prova de noexec ou de falta de espaço.\n' "$base" >&2
    return 1
}

fm_exec_dir() {
    local installation="$1" required="${2:-0}" base dir available probe
    local -A seen=()
    while IFS= read -r base; do
        [ -n "$base" ] && [ -z "${seen[$base]:-}" ] || continue
        seen[$base]=1
        [ -d "$base" ] && [ -w "$base" ] || { printf 'Diretório ausente ou não gravável: %s\n' "$base" >&2; continue; }
        # Mount flags are diagnostic hints. An actual ELF execution is decisive.
        fm_noexec "$base" && printf 'Filesystem noexec: %s (confirmando execução)\n' "$base" >&2
        dir=$(mktemp -d "$base/filemanager-$EUID-$(printf %s "$installation" | cksum | cut -d' ' -f1).XXXXXXXX") || continue
        chmod 700 "$dir" || { rmdir "$dir"; continue; }
        probe=/bin/true
        [ -f "$probe" ] || probe=/usr/bin/true
        if ! cp -- "$probe" "$dir/true"; then
            printf 'Não foi possível copiar a sonda ELF em %s; confira o erro de espaço/permissões acima.\n' "$base" >&2
            rm -f -- "$dir/true"; rmdir "$dir"; continue
        fi
        if ! chmod 700 "$dir/true"; then
            printf 'Não foi possível preparar as permissões da sonda ELF em %s.\n' "$base" >&2
            rm -f -- "$dir/true"; rmdir "$dir"; continue
        fi
        if ! "$dir/true" 2>/dev/null; then
            printf 'Execução ELF negada em %s (noexec, permissões ou política do host).\n' "$base" >&2
            rm -f -- "$dir/true"; rmdir "$dir"; continue
        fi
        rm -f -- "$dir/true"
        printf 'Execução ELF confirmada em %s.\n' "$base" >&2
        if ! available=$(fm_available_bytes "$dir"); then rmdir "$dir"; continue; fi
        if [ "$available" -lt "$((required + 1048576))" ]; then
            printf 'Espaço insuficiente em %s: disponíveis=%s, necessários=%s bytes.\n' "$base" "$available" "$((required + 1048576))" >&2
            rmdir "$dir"; continue
        fi
        printf '%s\n' "$dir"; return 0
    done < <(fm_candidate_dirs "$installation")
    printf '%s\n' 'Nenhum dos diretórios investigados permite preparar uma nova cópia executável com espaço comprovado. Confira os diagnósticos; runtimes existentes são pesquisados antes desta etapa.' >&2
    return 1
}

fm_binary_candidates() {
    local source="$1" root="$2" digest="$3" base record candidate key
    key=$(printf %s "$root" | cksum | cut -d' ' -f1)
    record="$root/.runtime/exec-$digest.path"
    if [ -f "$record" ] && [ ! -L "$record" ]; then
        IFS= read -r candidate < "$record" || candidate=''
        [ -z "$candidate" ] || printf '%s\n' "$candidate"
    fi
    while IFS= read -r base; do
        printf '%s\n' "$base/${source##*/}"
        # Bounded discovery: only this UID/installation, not other users' trees.
        for candidate in "$base/filemanager-$EUID-$key."*/"${source##*/}"; do
            [ ! -f "$candidate" ] || printf '%s\n' "$candidate"
        done
    done < <(fm_candidate_dirs "$root")
}

fm_cached_binary() {
    local source="$1" root="$2" digest="$3" candidate actual
    shift 3
    while IFS= read -r candidate; do
        [ "$candidate" != "$source" ] && fm_private_file "$candidate" || continue
        actual=$(fm_sha256 "$candidate") || continue
        if [ "$actual" != "$digest" ]; then
            printf 'Cópia rejeitada por SHA-256 diferente: %s\n' "$candidate" >&2; continue
        fi
        if "$candidate" "$@" >/dev/null 2>&1; then printf '%s\n' "$candidate"; return 0; fi
    done < <(fm_binary_candidates "$source" "$root" "$digest")
    return 1
}

fm_prepare_binary() (
    local source="$1" installation="$2" size dir digest cached cachefile rc metadata
    shift 2
    [ -f "$source" ] && [ -r "$source" ] || { printf 'Binário inexistente/inacessível: %s\n' "$source" >&2; return 1; }
    if "$source" "$@" >/dev/null 2>&1; then printf '%s\n' "$source"; return 0; else rc=$?; fi
    digest=$(fm_sha256 "$source") || return 1
    if cached=$(fm_cached_binary "$source" "$installation" "$digest" "$@"); then printf '%s\n' "$cached"; return 0; fi
    if fm_noexec "$source"; then printf 'Execução bloqueada por noexec: %s\n' "$source" >&2
    elif [ ! -x "$source" ] || [ "$rc" = 126 ]; then printf 'Execução negada (permissões/política): %s\n' "$source" >&2
    else printf 'Binário executou sem satisfazer requisitos ou é incompatível: %s (status %s, host %s)\n' "$source" "$rc" "$(uname -m)" >&2; return 1; fi
    # Serialize allocation and recheck the cache after acquiring the lock.
    mkdir -p "$installation/.runtime" || return 1
    [ ! -L "$installation/.runtime" ] || return 1
    cachefile="$installation/.runtime/exec-$digest.path"
    [ ! -L "$cachefile.lock" ] || return 1
    exec 6>"$cachefile.lock" || return 1
    flock -x 6 || return 1
    if cached=$(fm_cached_binary "$source" "$installation" "$digest" "$@"); then printf '%s\n' "$cached"; return 0; fi
    size=$(stat -c %s -- "$source" 2>/dev/null) || size=$(wc -c < "$source")
    [[ "$size" =~ ^[0-9]+$ ]] || { printf 'Não foi possível medir o binário: %s\n' "$source" >&2; return 1; }
    dir=$(fm_exec_dir "$installation" "$size") || return 1
    if cp -- "$source" "$dir/${source##*/}" && chmod 700 "$dir/${source##*/}" \
        && [ "$digest" = "$(fm_sha256 "$dir/${source##*/}")" ] \
        && fm_private_file "$dir/${source##*/}" \
        && "$dir/${source##*/}" "$@" >/dev/null; then
        metadata="$cachefile.$BASHPID"
        (umask 077; printf '%s\n' "$dir/${source##*/}" > "$metadata") && mv -f -- "$metadata" "$cachefile"
        printf '%s\n' "$dir/${source##*/}"; return 0
    fi
    rm -f -- "$dir/${source##*/}"; rmdir "$dir"
    printf 'Falha de cópia (inclusive ENOSPC), integridade, arquitetura ou execução: %s\n' "$source" >&2
    return 1
)

fm_php_candidates() {
    local root="$1" configured='' base candidate key
    [ ! -r "$root/.runtime/supervision/php" ] || IFS= read -r configured < "$root/.runtime/supervision/php" || :
    printf '%s\n' "$root/pcg" "${HOME:-$root}/.local/bin/pcg"
    [[ "$configured" = */pcg ]] && printf '%s\n' "$configured"
    key=$(printf %s "$root" | cksum | cut -d' ' -f1)
    for candidate in "$root/.runtime/exec-"*.path; do
        [ -f "$candidate" ] && [ ! -L "$candidate" ] || continue
        IFS= read -r configured < "$candidate" || configured=''
        [[ "$configured" = */pcg ]] && printf '%s\n' "$configured"
    done
    while IFS= read -r base; do
        printf '%s\n' "$base/pcg"
        for candidate in "$base/filemanager-$EUID-$key."*/pcg; do
            [ ! -f "$candidate" ] || printf '%s\n' "$candidate"
        done
    done < <(fm_candidate_dirs "$root")
    type -a -p pcg 2>/dev/null || :
}

# Keep the process status independent from the probe protocol. PHP/Swoole may
# emit startup warnings on either stream, even with a successful feature check.
fm_php_probe() {
    local binary="$1" code="$2" output rc line valid=0
    if output=$("$binary" -r "$code"); then rc=0; else rc=$?; fi
    while IFS= read -r line; do
        if [ "$line" = FM_PHP_RUNTIME_VALID ]; then valid=1
        elif [ -n "$line" ]; then printf '%s\n' "$line" >&2; fi
    done <<< "$output"
    if [ "$rc" -ne 0 ]; then
        printf 'PHP rejeitado: %s (status real do processo %s).\n' "$binary" "$rc" >&2
        return "$rc"
    fi
    if [ "$valid" -ne 1 ]; then
        printf 'PHP rejeitado: %s (status real do processo 0; resposta do teste ausente).\n' "$binary" >&2
        return 1
    fi
}

fm_php_code() {
    printf '%s\n' 'if (PHP_VERSION_ID < 80300) {fwrite(STDERR,"PHP >= 8.3 necessário\n");exit(1);} foreach (["swoole","zip","sockets","pcntl","posix"] as $e) {if (!extension_loaded($e)) {fwrite(STDERR,"Extensão ausente: $e\n");exit(1);}} if (PHP_OS_FAMILY !== "Linux") exit(1); echo "\nFM_PHP_RUNTIME_VALID\n";'
}

fm_php() {
    local root="$1" candidate resolved reference='' digest='' actual magic code rc
    local -a originals=() independent=()
    local -A seen=()
    root=$(cd -- "$root" && pwd -P) || return 1
    if [ -f "$root/pcg" ]; then
        reference=$(readlink -f -- "$root/pcg" 2>/dev/null) || reference="$root/pcg"
        digest=$(fm_sha256 "$reference") || digest=''
    fi
    code=$(fm_php_code)
    # Phase 1: exhaust existing runtimes BEFORE measuring free space or copying.
    while IFS= read -r candidate; do
        [ -n "$candidate" ] && [ -f "$candidate" ] || continue
        resolved=$(readlink -f -- "$candidate" 2>/dev/null) || resolved="$candidate"
        [[ "$resolved" = /* ]] || continue
        [ -z "${seen[$resolved]:-}" ] || continue
        seen[$resolved]=1
        if ! fm_private_file "$resolved"; then printf 'Runtime com proprietário/permissões inseguros: %s\n' "$candidate" >&2; continue; fi
        magic=''; IFS= read -r -N4 magic < "$resolved" || :
        if [ "$magic" != $'\177ELF' ]; then printf 'Runtime não é ELF: %s\n' "$candidate" >&2; continue; fi
        # Independent, privately owned runtimes can be different valid builds.
        # A broken persistent file must not veto an otherwise valid runtime.
        # Copies created by fm_prepare_binary still require matching SHA-256.
        if [ -n "$digest" ] && [ "$resolved" != "$reference" ]; then
            actual=$(fm_sha256 "$resolved") || continue
            if [ "$actual" != "$digest" ]; then
                if [[ "$resolved" = */filemanager-*/pcg ]]; then
                    printf 'Cache rejeitado por SHA-256 diferente da origem: %s\n' "$candidate" >&2
                else
                    # Prefer matching existing copies before independent builds;
                    # retain other PCGs as recovery options for a broken origin.
                    independent+=("$resolved")
                fi
                continue
            fi
        fi
        if fm_php_probe "$resolved" "$code"; then
            if [ -z "$reference" ] && [[ "$resolved" = /dev/shm/* || "$resolved" = /tmp/* || "$resolved" = /run/* ]]; then
                printf 'Aviso: runtime temporário validado sem pcg persistente em %s; recuperação após reboot exige preservar uma origem.\n' "$root" >&2
            fi
            printf '%s\n' "$resolved"; return 0
        else rc=$?; fi
        if [ "$rc" = 126 ] || fm_noexec "$resolved" || [ ! -x "$resolved" ]; then
            originals+=("$resolved")
        fi
    done < <(fm_php_candidates "$root")
    for candidate in "${independent[@]}"; do
        if fm_php_probe "$candidate" "$code"; then
            printf '%s\n' "$candidate"; return 0
        else rc=$?; fi
        if [ "$rc" = 126 ] || fm_noexec "$candidate" || [ ! -x "$candidate" ]; then
            originals+=("$candidate")
        fi
    done
    # Phase 2: recover from persistent sources; preparation itself is locked and
    # also rechecks caches so simultaneous starts cannot allocate duplicate ELFs.
    for candidate in "${originals[@]}"; do
        if resolved=$(fm_prepare_binary "$candidate" "$root" -r "$code") \
            && fm_php_probe "$resolved" "$code"; then
            printf '%s\n' "$resolved"; return 0
        fi
    done
    printf '%s\n' 'Nenhum PHP ELF >= 8.3 com Swoole, zip, sockets, pcntl e posix validado após investigar runtimes existentes e locais de recuperação. Veja os motivos acima.' >&2
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

fm_diagnose() {
    local root="$1" tool base dir
    printf 'Host: %s; UID: %s\n' "$(uname -m)" "$EUID"
    for tool in df stat findmnt sha256sum openssl python3; do
        printf '%s: ' "$tool"; type -P "$tool" || printf 'não encontrado\n'
    done
    printf 'df -h (saída e erros preservados):\n'
    df -h /dev/shm /tmp || printf 'df falhou com status %s\n' "$?"
    while IFS= read -r base; do
        printf 'Investigando %s\n' "$base"
        if dir=$(FILEMANAGER_EXEC_DIR="$base" fm_exec_dir "$root" 0); then
            rmdir -- "$dir"
        fi
    done < <(fm_candidate_dirs "$root")
}

# Remote agents must serialize dependencies too, rather than just public helpers.
fm_export_exec_helpers() {
    declare -f fm_noexec fm_candidate_dirs fm_sha256 fm_private_file fm_available_bytes fm_exec_dir \
        fm_binary_candidates fm_cached_binary fm_prepare_binary
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    case "${1:-}" in
        php) fm_php "$2" ;;
        diagnose) fm_diagnose "$2" ;;
        node) fm_node "$2" ;;
        run) root="$2"; shift 2; runtime=$(fm_php "$root") || exit 1; exec "$runtime" "$@" ;;
        *) exit 64 ;;
    esac
fi
