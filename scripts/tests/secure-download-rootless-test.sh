#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
source "$ROOT/scripts/secure-download.sh"
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/offline"

cat > "$TMP/bin/openssl" <<'MOCK'
#!/usr/bin/env bash
for option in -verify_return_error -verify_hostname -servername; do
    case " $* " in
        *" $option "*) ;;
        *) echo "Missing TLS verification option: $option" >&2; exit 2 ;;
    esac
done
cat >/dev/null
case "$TEST_HTTPS_SCENARIO" in
  valid) printf 'HTTP/1.0 200 OK\r\nContent-Length: 6\r\n\r\nabc123' ;;
  chunked) printf 'HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n3\r\n123\r\n0\r\n\r\n' ;;
  tls_error) printf 'HTTP/1.0 200 OK\r\nContent-Length: 6\r\n\r\nabc123'; exit 1 ;;
  downgrade) printf 'HTTP/1.0 302 Found\r\nContent-Length: 0\r\nLocation: http://example.com/file\r\n\r\n' ;;
  truncated) printf 'HTTP/1.0 200 OK\r\nContent-Length: 20\r\n\r\nabc' ;;
esac
MOCK
chmod +x "$TMP/bin/openssl"

# Force fallback even on hosts with curl/wget.
command() {
    if [ "${1:-}" = '-v' ] && { [ "${2:-}" = curl ] || [ "${2:-}" = wget ]; }; then
        return 1
    fi
    builtin command "$@"
}
export PATH="$TMP/bin:$PATH"

for scenario in valid chunked; do
    TEST_HTTPS_SCENARIO="$scenario" secure_download 'https://example.com/file' "$TMP/out"
    [ "$(cat "$TMP/out")" = abc123 ]
    rm -f -- "$TMP/out"
done
for scenario in tls_error downgrade truncated; do
    if TEST_HTTPS_SCENARIO="$scenario" secure_download 'https://example.com/file' "$TMP/out" 2>/dev/null; then
        echo "Untrusted HTTPS response accepted: $scenario" >&2
        exit 1
    fi
    [ ! -f "$TMP/out" ]
done

printf 'legitimate bytes' > "$TMP/source"
checksum="$(sha256sum "$TMP/source" | cut -d' ' -f1)"
secure_download "file://$TMP/source" "$TMP/out" "$checksum"
cmp "$TMP/out" "$TMP/source"
rm -f -- "$TMP/out"
if secure_download "file://$TMP/source" "$TMP/out" 2>/dev/null; then
    echo 'Unpinned local download accepted' >&2
    exit 1
fi

url='https://example.com/offline'
key="$(printf '%s' "$url" | sha256sum | cut -d' ' -f1)"
cp -- "$TMP/source" "$TMP/offline/$key.payload"
printf '%s\n' "$checksum" > "$TMP/offline/$key.sha256"
FILEMANAGER_OFFLINE_DIR="$TMP/offline" secure_download "$url" "$TMP/out" "$checksum"
cmp "$TMP/out" "$TMP/source"
rm -f -- "$TMP/out"
printf 'tampered' > "$TMP/offline/$key.payload"
if FILEMANAGER_OFFLINE_DIR="$TMP/offline" secure_download "$url" "$TMP/out" "$checksum" 2>/dev/null; then
    echo 'Corrupted offline artifact accepted' >&2
    exit 1
fi
[ ! -f "$TMP/out" ]
if FILEMANAGER_OFFLINE_DIR="$TMP/offline" secure_download "$url" "$TMP/out" "$(printf '0%.0s' {1..64})" 2>/dev/null; then
    echo 'Wrong expected digest accepted' >&2
    exit 1
fi
echo '[test][ok] HTTPS fallback, TLS failures, offline integrity, local archive pinning'
