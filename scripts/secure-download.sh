#!/usr/bin/env bash
# Shared by local installers. Authenticate HTTPS including every redirect.
# A local file fixture is accepted only with an explicit expected digest.
secure_download() {
    local url="$1" output="$2" expected="${3:-}" status
    case "$url" in
        https://*)
            if command -v curl >/dev/null 2>&1; then
                curl --disable --no-insecure --proto '=https' --proto-redir '=https' --fail --location \
                    --connect-timeout 15 --max-time 600 --retry 2 --output "$output" "$url"
                status=$?
            elif command -v wget >/dev/null 2>&1; then
                wget --check-certificate=on --https-only --timeout=30 --tries=2 --output-document="$output" "$url"
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
        [[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] \
            && printf '%s  %s\n' "$expected" "$output" | sha256sum --check --status \
            || { rm -f -- "$output"; return 1; }
    fi
}
