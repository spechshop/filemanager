#!/bin/bash
set -euo pipefail
mkdir -m 700 -p /home/restricted/app
cd /home/restricted/app
cp /runtime/php pcg
chmod 700 pcg
mkdir scripts
cp /repo/installer.sh /repo/filemanagerctl .
chmod 600 filemanagerctl
cp /repo/scripts/{runtime-exec,install-supervision,install-php-runtime,secure-download}.sh scripts/
cp /repo/scripts/pcg-release.py scripts/
export FILEMANAGER_PHP_URL=file:///runtime/php
export FILEMANAGER_PHP_SHA256=$(sha256sum /runtime/php | cut -d' ' -f1)
cat >server.php <<'PHP'
<?php
file_put_contents(__DIR__ . '/.runtime/actual-runtime', PHP_BINARY);
while (true) usleep(100000);
PHP
cp pcg /dev/shm/pcg
chmod 700 /dev/shm/pcg
/dev/shm/pcg -v
if ./pcg -v 2>home-denied; then exit 1; fi
cp /bin/true /tmp/true
if /tmp/true 2>tmp-denied; then exit 1; fi
! command -v sudo
! command -v systemctl
! command -v crontab
[ "$EUID" != 0 ]
size=$(wc -c < pcg)
available=$(stat -f -c '%a %S' /dev/shm)
read -r blocks blocksize <<< "$available"
[ "$((blocks * blocksize))" -lt "$size" ]
printf 'ELF size=%s, shm free=%s: insufficient for a second copy\n' "$size" "$((blocks * blocksize))"
mkdir -m700 /run/user/1000/tools
for name in df stat findmnt; do
    printf '#!/bin/bash\nprintf "%%s\\n" %s >>/home/restricted/%s.calls\nexit 126\n' "$name" "$name" > "/run/user/1000/tools/$name"
    chmod 700 "/run/user/1000/tools/$name"
done
# Optional before/after comparison. The baseline is explicitly supplied by the
# developer, so future commits do not change what the regression test expects.
if [ -s /runtime/before.sh ]; then
    mkdir /run/user/1000/before-tools
    cp /run/user/1000/tools/df /run/user/1000/before-tools/df
    # Here stat/findmnt still work, isolating the original silent-df bug.
    if PATH="/run/user/1000/before-tools:/usr/bin:/bin" /bin/bash /runtime/before.sh php "$PWD" >before.out 2>before.err; then
        echo 'FAIL: baseline unexpectedly succeeded'; exit 1
    fi
    grep -q 'Sem diretório gravável e executável' before.err
    cat before.err
    printf 'PASS before: existing /dev/shm/pcg was missed\n'
fi
rm -f /home/restricted/df.calls
# After: PATH lacks all basic utilities; installer must establish its own PATH.
export PATH=/run/user/1000/tools
/bin/bash installer.sh --supervision-only
[ "$(/bin/cat .runtime/supervision/php)" = /dev/shm/pcg ]
# start confirms process creation; wait for the child to execute PHP code.
for iteration in {1..50}; do
    [ ! -s .runtime/actual-runtime ] || break
    /bin/sleep 0.1
done
[ "$(/bin/cat .runtime/actual-runtime)" = /dev/shm/pcg ]
[ ! -e /home/restricted/df.calls ]
for iteration in {1..4}; do
    resolved=$(/bin/bash scripts/runtime-exec.sh php "$PWD")
    [ "$resolved" = /dev/shm/pcg ]
done
/bin/bash filemanagerctl start
/bin/bash filemanagerctl status
/bin/bash filemanagerctl stop
printf 'PASS after: supervision-only, incomplete PATH, blocked utilities, reuse without allocation\n'
export PATH="/run/user/1000/tools:/usr/bin:/bin"
/bin/bash scripts/runtime-exec.sh diagnose "$PWD" >diagnostic.out 2>diagnostic.err
grep -q 'df falhou com status 126' diagnostic.out
grep -q 'Execução ELF confirmada em /dev/shm' diagnostic.err
printf 'PASS diagnosis: silent df failure distinguished from noexec/space, statvfs available\n'
# SHA utility blocked too: digest verification must use the available OpenSSL.
printf '#!/bin/bash\nexit 126\n' > /run/user/1000/tools/sha256sum
chmod 700 /run/user/1000/tools/sha256sum
[ "$(/bin/bash scripts/runtime-exec.sh php "$PWD")" = /dev/shm/pcg ]
rm /run/user/1000/tools/sha256sum
# ELF + status 0 alone is not a PHP feature probe (true ignores its arguments).
mkdir -m700 /home/restricted/empty
cp /bin/true /run/user/1000/pcg
if FILEMANAGER_EXEC_DIR=/run/user/1000 /bin/bash scripts/runtime-exec.sh php /home/restricted/empty >fake.out 2>fake.err; then exit 1; fi
rm /run/user/1000/pcg
printf 'PASS probe: SHA-256 fallback and rejection of non-PHP ELF returning zero\n'
# Unknown capacity must remain 'unknown', never be mislabeled noexec/full.
printf '#!/bin/bash\nexit 126\n' > /run/user/1000/tools/python3
chmod 700 /run/user/1000/tools/python3
[ "$(/bin/bash scripts/runtime-exec.sh php "$PWD")" = /dev/shm/pcg ]
source scripts/runtime-exec.sh
[ "$(fm_strict_candidates "$PWD" 2>ranking.err | head -n1)" = /dev/shm ]
unknown=$(fm_exec_dir "$PWD" 0 2>unknown.err)
[[ "$unknown" = /dev/shm/* ]]
rmdir "$unknown"
grep -q 'Espaço desconhecido' unknown.err
grep -q 'Execução ELF confirmada' unknown.err
# Only ~30 MB is free in this tmpfs; unknown capacity is not assumed infinite.
cp pcg another-pcg
if FILEMANAGER_EXEC_DIR=/dev/shm fm_prepare_binary "$PWD/another-pcg" "$PWD" -v >full.out 2>full.err; then exit 1; fi
[ ! -s full.out ]
[ -z "$(find /dev/shm -name another-pcg -print)" ]
grep -q 'No space left on device' full.err
rm another-pcg
printf 'PASS real ENOSPC: failed copy rejected and partial ELF removed\n'
rm /run/user/1000/tools/python3
printf 'PASS unknown capacity: reuse succeeds; allocation defaults to executable shm\n'
# Runtime discovered in a managed directory even without its pointer record.
export PATH="/run/user/1000/tools:/usr/bin:/bin"
key=$(printf %s "$PWD" | cksum | cut -d' ' -f1)
cache=$(mktemp -d "/dev/shm/filemanager-$EUID-$key.XXXXXXXX")
mv /dev/shm/pcg "$cache/pcg"
[ "$(/bin/bash scripts/runtime-exec.sh php "$PWD")" = "$cache/pcg" ]
mv "$cache/pcg" /dev/shm/pcg; rmdir "$cache"
# Existing mismatched ELF must be rejected BEFORE the PHP feature probe.
cache=$(mktemp -d "/dev/shm/filemanager-$EUID-$key.XXXXXXXX")
mv /dev/shm/pcg "$cache/pcg"
printf x | dd of="$cache/pcg" bs=1 seek=9 conv=notrunc status=none
if FILEMANAGER_EXEC_DIR=/dev/shm /bin/bash scripts/runtime-exec.sh php "$PWD" >mismatch.out 2>mismatch.err; then exit 1; fi
grep -q 'SHA-256 diferente' mismatch.err
rm "$cache/pcg"; rmdir "$cache"
cp pcg /dev/shm/pcg
# Unsafe permissions and a shell script named pcg must not be executed.
chmod 777 /dev/shm/pcg
if FILEMANAGER_EXEC_DIR=/dev/shm /bin/bash scripts/runtime-exec.sh php "$PWD" >unsafe.out 2>unsafe.err; then exit 1; fi
grep -q 'permissões inseguros' unsafe.err
chmod 700 /dev/shm/pcg
rm /dev/shm/pcg
printf '#!/bin/bash\ntouch /home/restricted/UNTRUSTED_EXECUTED\n' > /dev/shm/pcg
chmod 700 /dev/shm/pcg
if FILEMANAGER_EXEC_DIR=/run/user/1000 /bin/bash scripts/runtime-exec.sh php "$PWD" >notelf.out 2>notelf.err; then exit 1; fi
[ ! -e /home/restricted/UNTRUSTED_EXECUTED ]
grep -q 'não é ELF' notelf.err
rm /dev/shm/pcg
printf 'PASS integrity: corrupt hash, unsafe permissions, non-ELF rejected\n'
# No runtime exists now. df/stat/findmnt all fail; Python statvfs measures space.
# Four simultaneous resolvers must allocate one ELF, not exhaust the tmpfs.
pids=()
for iteration in {1..4}; do
    /bin/bash scripts/runtime-exec.sh php "$PWD" >"recovery.$iteration" 2>"recovery.$iteration.err" & pids+=("$!")
done
for pid in "${pids[@]}"; do wait "$pid"; done
recovered=$(cat recovery.1)
for iteration in {2..4}; do [ "$(cat "recovery.$iteration")" = "$recovered" ]; done
[ "$recovered" != /dev/shm/pcg ]
"$recovered" -v
[ "$(sha256sum pcg | cut -d' ' -f1)" = "$(sha256sum "$recovered" | cut -d' ' -f1)" ]
[ -e /home/restricted/df.calls ]
grep -q 'tentando stat/statvfs' recovery.*.err
printf 'PASS allocation: four concurrent starts, one verified ELF, statvfs fallback\n'
# Reuse must not need any free-space utility even when all of them fail.
# Delete the disposable copy to simulate /dev/shm cleanup, preserving pcg/state.
rm "$recovered"; rmdir "${recovered%/*}"
export PATH=/run/user/1000/tools
/bin/bash installer.sh --supervision-only
/bin/bash filemanagerctl start
/bin/bash filemanagerctl status
/bin/bash filemanagerctl disable
printf 'PASS reboot recovery: persistent source rebuilt; supervision uses recovered PHP\n'
