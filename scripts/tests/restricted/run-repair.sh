#!/bin/bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd -P)
# Fixtures: php, baseline runtime-exec.sh/install-codex-native.sh, official
# Node 24 node.tar.xz and its SHASUMS256.txt (verified by the test).
fixtures=${1:-$root/.runtime/installer-repair-fixtures}
docker build -t filemanager-repair-test -f "$root/scripts/tests/restricted/Dockerfile.repair" "$root/scripts/tests/restricted"
docker run --rm --read-only --cap-drop ALL \
    --tmpfs /home:rw,noexec,nosuid,nodev,size=1g,mode=1777 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=256m,mode=1777 \
    --tmpfs /dev/shm:rw,exec,nosuid,nodev,size=1g,mode=1777 \
    -v "$root:/repo:ro" -v "$fixtures:/runtime:ro" \
    filemanager-repair-test bash /repo/scripts/tests/restricted/installer-repair.sh
