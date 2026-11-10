#!/usr/bin/env bash
# Shared prerequisite for full installation and supervision repair.
source "$(dirname -- "${BASH_SOURCE[0]}")/runtime-exec.sh" || return 1
source "$(dirname -- "${BASH_SOURCE[0]}")/secure-download.sh" || return 1

fm_install_php() (
    local root="$1" runtime stage expected source_digest copied_digest code
    root=$(cd -- "$root" && pwd -P) || return 1
    if ! runtime=$(fm_php "$root"); then
        # Legacy installations can retain ./php on a noexec filesystem.
        if [ ! -e "$root/pcg" ] && fm_private_file "$root/php"; then
            cp -- "$root/php" "$root/pcg" && chmod 700 "$root/pcg"
            runtime=$(fm_php "$root") || runtime=''
        fi
        if [ -z "$runtime" ]; then
            stage=$(mktemp -d "$root/.php-download.XXXXXXXX") || return 1
            trap 'rm -rf -- "$stage"' EXIT
            expected="${FILEMANAGER_PHP_SHA256:-9832bcfd4d731d69a30981e2e1cc5490a908e4f99abf4cc83ae0e5df4470ee78}"
            printf 'Recuperando PHP pelo download configurado, com SHA-256 obrigatório.\n' >&2
            if ! secure_download "${FILEMANAGER_PHP_URL:-https://github.com/spechshop/pcg729/releases/download/PCG729/php}" "$stage/pcg" "$expected"; then
                printf 'Não foi possível recuperar PHP: download ou integridade inválidos.\n' >&2
                return 1
            fi
            chmod 700 "$stage/pcg" || return 1
            code=$(fm_php_code)
            runtime=$(fm_prepare_binary "$stage/pcg" "$root" -r "$code") \
                && fm_php_probe "$runtime" "$code" || return 1
            # Publish only after a real feature probe, including on noexec hosts.
            mv -f -- "$stage/pcg" "$root/pcg" || return 1
            if [ "$runtime" = "$stage/pcg" ]; then runtime="$root/pcg"; fi
            # fm_prepare_binary records relocated copies under the real root;
            # no second allocation is needed after publishing the original.
        fi
    fi
    # Preserve an independently discovered temporary runtime for the next boot.
    # Atomic replacement also repairs a corrupt persistent original.
    if [ "$runtime" != "$root/pcg" ]; then
        source_digest=$(fm_sha256 "$runtime") || return 1
        copied_digest=$(fm_sha256 "$root/pcg" 2>/dev/null) || copied_digest=''
        if [ "$source_digest" != "$copied_digest" ]; then
            stage=$(mktemp -d "$root/.php-preserve.XXXXXXXX") || return 1
            trap 'rm -rf -- "$stage"' EXIT
            cp -- "$runtime" "$stage/pcg" && chmod 700 "$stage/pcg" \
                && [ "$(fm_sha256 "$stage/pcg")" = "$source_digest" ] \
                && mv -f -- "$stage/pcg" "$root/pcg" || return 1
            printf 'Origem persistente PHP recuperada em %s/pcg.\n' "$root" >&2
        fi
    fi
    printf '%s\n' "$runtime"
)
