#!/bin/bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd -P)
node_root=${1:-$root/.runtime/node}
php_source=${SECURITY_PHP_BINARY:-$(command -v php)}
staging=$(mktemp -d "$root/scripts/tests/restricted/.runtime.XXXXXXXX")
trap 'rm -rf -- "$staging"' EXIT
cp -- "$php_source" "$staging/php"
chmod 755 "$staging" "$staging/php"
docker build -t filemanager-repair-test -f "$root/scripts/tests/restricted/Dockerfile.repair" "$root/scripts/tests/restricted"
for uid in 1000 0; do
    docker run --rm --read-only --cap-drop ALL --user "$uid:$uid" \
        --tmpfs /tmp:rw,exec,nosuid,nodev,size=1g,mode=1777 \
        --tmpfs /dev/shm:rw,exec,nosuid,nodev,size=512m,mode=1777 \
        -v "$root:/repo:ro" -v "$node_root:/node:ro" -v "$staging:/runtime:ro" \
        filemanager-repair-test bash /repo/scripts/tests/restricted/terminal-native.sh
done
docker run --rm --read-only --cap-drop ALL \
    --tmpfs /home:rw,noexec,nosuid,nodev,size=1g,mode=1777 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=256m,mode=1777 \
    --tmpfs /dev/shm:rw,exec,nosuid,nodev,size=512m,mode=1777 \
    -v "$root:/repo:ro" -v "$node_root:/node:ro" \
    filemanager-repair-test bash /repo/scripts/tests/restricted/terminal-profile.sh
docker run --rm --read-only --cap-drop ALL \
    --tmpfs /home:rw,noexec,nosuid,nodev,size=1g,mode=1777 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=256m,mode=1777 \
    --tmpfs /dev/shm:rw,exec,nosuid,nodev,size=512m,mode=1777 \
    -v "$root:/repo:ro" -v "$staging:/runtime:ro" \
    filemanager-repair-test bash /repo/scripts/tests/restricted/pcg-terminal.sh
