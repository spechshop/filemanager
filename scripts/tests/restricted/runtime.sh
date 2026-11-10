#!/bin/bash
set -euo pipefail
mkdir -p /home/restricted/app
cd /home/restricted/app
cp /runtime/php pcg
chmod 700 pcg
if ./pcg -v 2>denied.log; then echo 'FAIL: home executável'; exit 1; fi
grep -q 'Permission denied' denied.log
cp /runtime/php /tmp/pcg
if /tmp/pcg -v 2>denied-tmp.log; then echo 'FAIL: tmp executável'; exit 1; fi
source /repo/scripts/runtime-exec.sh
runtime=$(fm_php "$PWD")
[[ "$runtime" = /dev/shm/* ]]
"$runtime" -r 'if (!extension_loaded("swoole")) exit(1); echo PHP_VERSION, " Swoole ", swoole_version(), "\n";'
[ "$(sha256sum pcg | cut -d' ' -f1)" = "$(sha256sum "$runtime" | cut -d' ' -f1)" ]
[ "$(fm_php "$PWD")" = "$runtime" ]
# Simulate loss of the disposable runtime at reboot, keeping persistent source.
rm -f "$runtime"; rmdir "${runtime%/*}"
runtime=$(fm_php "$PWD")
"$runtime" -v
if FILEMANAGER_EXEC_DIR=/tmp fm_prepare_binary "$PWD/pcg" "$PWD/other" -v; then exit 1; fi
if fm_exec_dir "$PWD" 999999999999999; then exit 1; fi
if FILEMANAGER_EXEC_DIR=/root fm_exec_dir "$PWD" 0; then exit 1; fi
if touch /usr/forbidden 2>/dev/null; then exit 1; fi
! command -v sudo
! command -v systemctl
"$runtime" /repo/scripts/tests/restricted/pty-jobs.php
cp /repo/composer.json /repo/composer.lock /repo/composer .
export COMPOSER_HOME="$PWD/.composer" HOME=/home/restricted
"$runtime" composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist
"$runtime" composer check-platform-reqs --no-dev
"$runtime" -r 'require "vendor/autoload.php"; echo "PASS Composer autoload\n";'
# Repeat with a partially missing vendor tree, preserving the lock.
rm -f vendor/autoload.php
"$runtime" composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist
"$runtime" -r 'require "vendor/autoload.php";'
cp /repo/server.php /repo/middleware.php .
cp -r /repo/plugins plugins
mkdir -p scripts
cp /repo/scripts/runtime-exec.sh scripts/
ln -s /repo/libspech libspech
: > .env
mkdir -p files
python3 - <<'PYTHON'
import json
from pathlib import Path
p=Path('plugins/configInterface.json')
c=json.loads(p.read_text()); c.update(host='127.0.0.1', port=18080, ssl=True)
c['serverSettings'].update(worker_num=1, ssl_cert_file=str(Path('cert.pem').resolve()), ssl_key_file=str(Path('key.pem').resolve()))
c['fileManager']['services'] = dict(pty=False, lsp=False, gpt=False, codex=False)
p.write_text(json.dumps(c))
PYTHON
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost -keyout key.pem -out cert.pem 2>/dev/null
export FILEMANAGER_CONFIG_FILE="$PWD/plugins/configInterface.json"
"$runtime" server.php >server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT
for attempt in {1..60}; do
    if curl -fsS --cacert cert.pem --resolve localhost:18080:127.0.0.1 https://localhost:18080/ > response.html; then break; fi
    sleep 0.2
done
grep -qi '<html' response.html || { cat server.log; exit 1; }
kill "$server_pid"; wait "$server_pid" || true
trap - EXIT
printf 'PASS Composer clean/repeated/partial install and real server on noexec home\n'
cp /repo/filemanagerctl .
bash filemanagerctl __configure "$PWD/pcg" filemanager-restricted-test.service background
bash filemanagerctl start
bash filemanagerctl start
bash filemanagerctl status
bash filemanagerctl restart
bash filemanagerctl stop
if bash filemanagerctl status; then exit 1; fi
bash filemanagerctl __tick
sleep 3
if bash filemanagerctl status; then exit 1; fi
bash filemanagerctl start
bash filemanagerctl disable
if bash filemanagerctl status; then exit 1; fi
printf 'PASS real controller on noexec: start twice/status/restart/stop/tick/start/disable\n'


printf 'PASS: uid=%s; home/tmp noexec, usr read-only, ELF/Swoole, SHA256, reuse, recovery, no executable directory, insufficient space, inaccessible directory, PTY jobs\n' "$(id -u)"
