#!/usr/bin/env bash
# Shared prerequisite for full installation and supervision repair.
source "$(dirname -- "${BASH_SOURCE[0]}")/runtime-exec.sh" || return 1
source "$(dirname -- "${BASH_SOURCE[0]}")/secure-download.sh" || return 1

fm_pcg_release() (
    local metadata='' base interpreter=''
    for interpreter in python3 python3.13 python3.12 python3.11 python3.10 python3.9 python3.8; do
        if command -v "$interpreter" >/dev/null 2>&1 && "$interpreter" --version >/dev/null 2>&1; then break; fi
        interpreter=''
    done
    [ -n "$interpreter" ] || {
        printf 'Python 3 necessário para interpretar os metadados oficiais do PCG.\n' >&2; return 1;
    }
    for base in "${TMPDIR:-/tmp}" /dev/shm /var/tmp; do
        [ -d "$base" ] && [ -w "$base" ] || continue
        metadata=$(mktemp "$base/pcg-release.XXXXXXXX.json") && break
    done
    [ -n "$metadata" ] || return 1
    trap 'rm -f -- "$metadata"' EXIT
    secure_download https://api.github.com/repos/spechshop/pcg729/releases/latest "$metadata" || return 1
    "$interpreter" "$(dirname -- "${BASH_SOURCE[0]}")/pcg-release.py" "$metadata" "$(uname -m)"
)

fm_install_php() (
    local root="$1" runtime preserve stage expected source_digest copied_digest code strict=0 base downloaded=0 keep_stage=0
    root=$(cd -- "$root" && pwd -P) || return 1
    if fm_noexec "$root"; then strict=1; fm_strict_diagnose; fi
    local fallback='' release url='' required=0 tag='' cached
    runtime=$(fm_php "$root") || runtime=''
    fallback="$runtime"
    if [ -n "${FILEMANAGER_PHP_URL:-}" ]; then
        url="$FILEMANAGER_PHP_URL"
        expected="${FILEMANAGER_PHP_SHA256:-}"
        [[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] || {
            printf 'URL PCG personalizada exige FILEMANAGER_PHP_SHA256.\n' >&2; return 1;
        }
    elif release=$(fm_pcg_release); then
        IFS=$'\t' read -r url expected required tag <<< "$release"
        printf 'PCG oficial selecionado: %s; SHA-256 %s.\n' "$tag" "$expected" >&2
        if [ -n "$runtime" ] && [ "$(fm_sha256 "$runtime")" != "$expected" ]; then
            printf 'Atualizando PCG existente para a release atual %s.\n' "$tag" >&2
            runtime=''
        fi
        if [ -z "$runtime" ] \
            && cached=$(fm_cached_binary "$root/pcg" "$root" "$expected" -r "$(fm_php_code)") \
            && fm_php_probe "$cached" "$(fm_php_code)"; then
            runtime="$cached"
            printf 'Reutilizando cópia íntegra da release atual em %s.\n' "$runtime" >&2
        fi
    elif [ -n "$runtime" ]; then
        printf 'Release atual indisponível; mantendo o PCG existente validado.\n' >&2
    else
        printf 'Sem PCG válido e sem metadados oficiais verificáveis para o download.\n' >&2
        return 1
    fi
    if [ -z "$runtime" ]; then
        if [ "$strict" = 0 ]; then strict=1; fm_strict_diagnose; fi
        code=$(fm_php_code)
        while IFS= read -r base; do
            stage=$(FILEMANAGER_EXEC_DIR="$base" fm_exec_dir "$root" "$required") || continue
            trap '[ "$keep_stage" = 1 ] || rm -rf -- "$stage"' EXIT
            printf 'Baixando PCG diretamente em %s (SHA-256 obrigatório).\n' "$stage" >&2
            if secure_download "$url" "$stage/pcg" "$expected" \
                && chmod 700 "$stage/pcg" && fm_php_probe "$stage/pcg" "$code"; then
                runtime="$stage/pcg"
                downloaded=1
                break
            fi
            printf 'Download, integridade ou execução falhou em %s; tentando outro local.\n' "$base" >&2
            rm -rf -- "$stage"
        done < <(fm_strict_candidates "$root")
        if [ "$downloaded" != 1 ]; then
            printf 'Não foi possível recuperar PHP: nenhum local permitiu download íntegro e execução válida.\n' >&2
            if [ -z "$fallback" ]; then return 1; fi
            runtime="$fallback"
            printf 'Mantendo PCG anterior validado após falha da atualização.\n' >&2
        fi
    fi
    # Preserve an independently discovered temporary runtime for the next boot.
    # Atomic replacement also repairs a corrupt persistent original.
    if [ "$runtime" != "$root/pcg" ]; then
        source_digest=$(fm_sha256 "$runtime") || return 1
        copied_digest=$(fm_sha256 "$root/pcg" 2>/dev/null) || copied_digest=''
        if [ "$source_digest" != "$copied_digest" ]; then
            preserve=$(mktemp -d "$root/.php-preserve.XXXXXXXX") || return 1
            trap 'rm -rf -- "$preserve"; [ "$keep_stage" = 1 ] || { [ -z "${stage:-}" ] || rm -rf -- "$stage"; }' EXIT
            cp -- "$runtime" "$preserve/pcg" && chmod 700 "$preserve/pcg" \
                && [ "$(fm_sha256 "$preserve/pcg")" = "$source_digest" ] \
                && mv -f -- "$preserve/pcg" "$root/pcg" || return 1
            printf 'Origem persistente PHP recuperada em %s/pcg.\n' "$root" >&2
        fi
    fi
    if [ "$strict" = 1 ]; then
        printf 'Modo estrito: PHP validado em %s. Saída do teste:\n' "$runtime" >&2
        "$runtime" -r 'var_dump(1);' >&2 || return $?
    fi
    keep_stage=1
    printf '%s\n' "$runtime"
)
