#!/bin/bash
set -euo pipefail
mkdir -p /home/restricted/app/scripts
cd /home/restricted/app
cp /repo/installer.sh /repo/filemanagerctl .
cp /repo/scripts/{runtime-exec,install-php-runtime,secure-download,install-supervision}.sh scripts/
cp /repo/scripts/pcg-release.py scripts/
cp /runtime/php pcg
chmod 700 pcg
cp pcg /dev/shm/pcg
printf '<?php file_put_contents(__DIR__."/started", PHP_BINARY); while(true) usleep(100000);' > server.php
# Only the static PCG binary; no extension loading or injected php.ini.
unset PHPRC PHP_INI_SCAN_DIR
export FILEMANAGER_PHP_URL=file:///runtime/php
export FILEMANAGER_PHP_SHA256=$(sha256sum /runtime/php | cut -d' ' -f1)
/dev/shm/pcg -v
/dev/shm/pcg -r 'foreach (["swoole","zip","sockets","pcntl","posix"] as $e) if (!extension_loaded($e)) exit(3); exit(PHP_VERSION_ID >= 80300 ? 0 : 2);'
! ./pcg -v
cp /bin/true /tmp/true
! /tmp/true
! command -v sudo
[ "$EUID" = 1000 ]
findmnt -n -o TARGET,OPTIONS -T /home/restricted/app
findmnt -n -o TARGET,OPTIONS -T /tmp
findmnt -n -o TARGET,OPTIONS -T /dev/shm
export PATH=/nonexistent
/bin/bash installer.sh --supervision-only
[ "$(/bin/cat .runtime/supervision/php)" = /dev/shm/pcg ]
/bin/bash filemanagerctl disable
export PATH=/usr/bin:/bin
source scripts/install-php-runtime.sh
fm_php_probe /dev/shm/pcg 'echo "WARNING: regression output\nFM_PHP_RUNTIME_VALID\n";' 2>warning.err
grep 'WARNING: regression output' warning.err
printf 'PASS PCG-only: probe tolerates warning text without loading extensions\n'
# Real exit codes and marker are independently required.
if fm_php_probe /dev/shm/pcg 'echo "FM_PHP_RUNTIME_VALID\n"; exit(42);' 2>exit.err; then exit 1; fi
grep 'status real do processo 42' exit.err
if fm_php_probe /bin/true ignored 2>marker.err; then exit 1; fi
grep 'resposta do teste ausente' marker.err
printf 'PASS probe: warnings tolerated; nonzero exit and absent response rejected\n'
# Full installer PHP phase, extracted verbatim to avoid package/service/token
# side effects unrelated to runtime preparation. Supervision uses the full entry.
python3 - <<'PY'
s=open('installer.sh').read(); a=s.index('# 9) Runtime PHP/Swoole'); a=s.index('CURRENT_DIR=',a); b=s.index('# BEGIN installer token generation',a)
open('full-php-stage.sh','w').write(s[a:b]+'printf "%s\\n" "$PHP_BIN"\n')
PY
[ "$(bash full-php-stage.sh)" = /dev/shm/pcg ]
printf 'broken persistent binary' > pcg
[ "$(bash full-php-stage.sh)" = /dev/shm/pcg ]
cmp pcg /dev/shm/pcg
printf 'PASS full mode PHP stage: valid temporary runtime repairs corrupt persistent original\n'
rm /dev/shm/pcg
export PATH=/nonexistent
/bin/bash installer.sh --supervision-only
export PATH=/usr/bin:/bin
recovered=$(cat .runtime/supervision/php)
[[ "$recovered" = /dev/shm/* ]]
cmp pcg "$recovered"
bash filemanagerctl disable
rm "$recovered"
[ -x "$(bash full-php-stage.sh)" ]
printf 'PASS deletion: both installer modes recover temporary PHP from noexec persistent source\n'
# Isolate discovery for download scenarios, preserving real copy/probe behavior.
fm_php_candidates() { printf '%s\n' "$1/pcg"; }
export FILEMANAGER_PHP_URL=file:///runtime/php
export FILEMANAGER_PHP_SHA256=$(fm_sha256 /runtime/php)
printf 'invalid' > pcg
runtime=$(fm_install_php "$PWD" 2>download.err)
grep 'Baixando PCG diretamente em /dev/shm/' download.err
grep 'Modo estrito detectado' download.err
grep 'int(1)' download.err
cmp pcg /runtime/php
"$runtime" -r 'echo "PASS verified download and feature validation\n";'
rm pcg
runtime=$(fm_install_php "$PWD" 2>download.err)
grep 'Baixando PCG diretamente em /dev/shm/' download.err
grep 'Modo estrito detectado' download.err
grep 'int(1)' download.err
cmp pcg /runtime/php
printf 'PASS download: absent and invalid persistent source repaired\n'
printf 'invalid preserved' > pcg
export FILEMANAGER_PHP_SHA256=$(printf '%064d' 0)
if fm_install_php "$PWD" >bad.out 2>bad.err; then exit 1; fi
grep 'nenhum local permitiu download íntegro' bad.err
[ "$(cat pcg)" = 'invalid preserved' ]
printf 'PASS integrity: incorrect download hash fails without replacing persistent file\n'
# An old saved system-PHP setting must never re-enter PCG discovery.
printf '/usr/bin/php\n' > .runtime/supervision/php
source scripts/runtime-exec.sh
if fm_php_candidates "$PWD" | grep -E '/(php|php[0-9.]+)$'; then exit 1; fi
printf 'PASS PCG-only discovery: system PHP and stale saved PHP path excluded\n'
if [ "${FM_TEST_LATEST_PCG:-0}" = 1 ]; then
    unset FILEMANAGER_PHP_URL FILEMANAGER_PHP_SHA256
    runtime=$(fm_install_php "$PWD")
    IFS=$'\t' read -r url digest size tag <<< "$(fm_pcg_release)"
    [ "$(fm_sha256 "$runtime")" = "$digest" ]
    [ "$(fm_sha256 pcg)" = "$digest" ]
    "$runtime" -v
    "$runtime" -r 'foreach (["swoole","zip","sockets","pcntl","posix"] as $e) if (!extension_loaded($e)) exit(1);'
    printf 'PASS latest PCG: %s, official HTTPS, published SHA-256, static extensions\n' "$tag"
    cp /runtime/php pcg
    cp /runtime/php /dev/shm/pcg
    updated=$(fm_install_php "$PWD" 2>update.err)
    [ "$(fm_sha256 "$updated")" = "$digest" ]
    [ "$(fm_sha256 pcg)" = "$digest" ]
    grep 'Reutilizando cópia íntegra da release atual' update.err
    printf 'PASS upgrade: older valid PCG upgraded using verified current cache\n'
    # Failure to fetch a newer version must not discard an executable PCG.
    (fm_pcg_release() { return 1; }
     [ "$(fm_install_php "$PWD")" = "$updated" ])
    printf 'PASS offline: validated PCG retained when release metadata is unavailable\n'
fi
# Actual official Node.js 24 archive, authenticated against its release sums.
expected=$(awk '$2 ~ /linux-x64.tar.xz$/ {print $1}' /runtime/SHASUMS256.txt)
[ "$(fm_sha256 /runtime/node.tar.xz)" = "$expected" ]
source /repo/scripts/install-codex-native.sh
extract_node_runtime_archive /runtime/node.tar.xz tar.xz "$PWD/node"
if (source /runtime/install-codex-native.sh; validate_node_runtime_layout "$PWD/node"); then exit 1; fi
[ -f node/bin/node ] && [ -f node/lib/node_modules/npm/bin/npm-cli.js ]
validate_node_runtime_layout "$PWD/node"
node=$(fm_prepare_binary "$PWD/node/bin/node" "$PWD" --version)
[[ "$("$node" --version)" = v24.* ]]
"$node" "$PWD/node/lib/node_modules/npm/bin/npm-cli.js" --version
printf 'PASS Node.js 24: baseline layout rejected on noexec; fixed layout, relocated ELF and npm execute\n'
