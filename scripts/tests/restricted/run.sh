#!/bin/bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd -P)
php_source=${SECURITY_PHP_BINARY:-$(command -v php)}
# Docker daemon may be outside the current namespace; stage only the runtime.
staging=$(mktemp -d "$root/scripts/tests/restricted/.runtime.XXXXXXXX")
trap 'rm -f -- "$staging/php"; rmdir -- "$staging"' EXIT
cp -- "$php_source" "$staging/php"
docker build -t filemanager-restricted-test -f "$root/scripts/tests/restricted/Dockerfile" "$root/scripts/tests/restricted"
docker run --rm --read-only --cap-drop ALL \
    --tmpfs /home:rw,noexec,nosuid,nodev,size=512m,mode=1777 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=256m,mode=1777 \
    --tmpfs /dev/shm:rw,exec,nosuid,nodev,size=512m,mode=1777 \
    -v "$root:/repo:ro" -v "$staging:/runtime:ro" \
    filemanager-restricted-test bash /repo/scripts/tests/restricted/runtime.sh
