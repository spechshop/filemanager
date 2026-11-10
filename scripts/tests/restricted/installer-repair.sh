#!/bin/bash
set -euo pipefail
mkdir -p /home/restricted/app/scripts
cd /home/restricted/app
cp /repo/installer.sh /repo/filemanagerctl .
cp /repo/scripts/{runtime-exec,install-php-runtime,secure-download,install-supervision}.sh scripts/
cp /runtime/php pcg
chmod 700 pcg
cp pcg /dev/shm/pcg
printf '<?php file_put_contents(__DIR__."/started", PHP_BINARY); while(true) usleep(100000);' > server.php
# The binary already embeds Swoole; asking the loader to load it again emits
# genuine PHP startup warnings while all required extensions remain available.
printf 'extension=swoole\ndisplay_errors=1\nlog_errors=1\n' > /home/restricted/php.ini
export PHPRC=/home/restricted/php.ini
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
if bash /runtime/runtime-exec.sh php "$PWD" >before.out 2>before.err; then echo 'baseline unexpectedly succeeded'; exit 1; fi
grep 'status 1' before.err
printf 'PASS baseline: valid PHP rejected with warnings and misleading status 1\n'
export PATH=/nonexistent
/bin/bash installer.sh --supervision-only
[ "$(/bin/cat .runtime/supervision/php)" = /dev/shm/pcg ]
/bin/bash filemanagerctl disable
export PATH=/usr/bin:/bin
source scripts/install-php-runtime.sh
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
runtime=$(fm_install_php "$PWD")
cmp pcg /runtime/php
"$runtime" -r 'echo "PASS verified download and feature validation\n";'
rm pcg
runtime=$(fm_install_php "$PWD")
cmp pcg /runtime/php
printf 'PASS download: absent and invalid persistent source repaired\n'
printf 'invalid preserved' > pcg
export FILEMANAGER_PHP_SHA256=$(printf '%064d' 0)
if fm_install_php "$PWD" >bad.out 2>bad.err; then exit 1; fi
grep 'integridade inválidos' bad.err
[ "$(cat pcg)" = 'invalid preserved' ]
printf 'PASS integrity: incorrect download hash fails without replacing persistent file\n'
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
