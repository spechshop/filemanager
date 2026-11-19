#!/usr/bin/env bash
# Shared downloader. TLS hostname/certificate checks apply on every HTTPS redirect.
# Offline cache is keyed by SHA256(URL), with operator-supplied SHA256 sidecars.
secure_download() {
    local url="$1" output="$2" expected="${3:-}" status=1
    local cache_key cached cached_pin pin
    if [ -n "$expected" ] && [[ ! "$expected" =~ ^[a-fA-F0-9]{64}$ ]]; then
        return 1
    fi
    case "$url" in
        https://*)
            if [ -n "${FILEMANAGER_OFFLINE_DIR:-}" ]; then
                cache_key="$(printf '%s' "$url" | sha256sum | cut -d' ' -f1)" || return 1
                cached="${FILEMANAGER_OFFLINE_DIR%/}/$cache_key.payload"
                cached_pin="${FILEMANAGER_OFFLINE_DIR%/}/$cache_key.sha256"
                if [ -e "$cached" ] || [ -e "$cached_pin" ]; then
                    [ -f "$cached" ] && [ -f "$cached_pin" ] || return 1
                    pin="$(tr -d '[:space:]' < "$cached_pin")" || return 1
                    [[ "$pin" =~ ^[a-fA-F0-9]{64}$ ]] || return 1
                    if [ -n "$expected" ] && [ "${expected,,}" != "${pin,,}" ]; then
                        return 1
                    fi
                    expected="$pin"
                    cp -- "$cached" "$output" || { rm -f -- "$output"; return 1; }
                    printf '%s  %s\n' "$expected" "$output" | sha256sum --check --status \
                        || { rm -f -- "$output"; return 1; }
                    return 0
                fi
            fi
            if command -v curl >/dev/null 2>&1; then
                curl --disable --no-insecure --proto '=https' --proto-redir '=https' --fail --location \
                    --connect-timeout 15 --max-time 600 --retry 2 --output "$output" "$url"
                status=$?
            elif command -v wget >/dev/null 2>&1; then
                wget --check-certificate=on --https-only --timeout=30 --tries=2 --output-document="$output" "$url"
                status=$?
            elif command -v perl >/dev/null 2>&1 && command -v openssl >/dev/null 2>&1 \
                && [ -r "${BASH_SOURCE[0]%/*}/https-openssl-download.pl" ]; then
                perl "${BASH_SOURCE[0]%/*}/https-openssl-download.pl" "$url" "$output"
                status=$?
            else
                return 1
            fi
            ;;
        file://*)
            [[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] || return 1
            cp -- "${url#file://}" "$output"
            status=$?
            ;;
        *) return 1 ;;
    esac
    if [ "$status" -ne 0 ]; then rm -f -- "$output"; return 1; fi
    if [ -n "$expected" ]; then
        printf '%s  %s\n' "$expected" "$output" | sha256sum --check --status \
            || { rm -f -- "$output"; return 1; }
    fi
    return 0
}
