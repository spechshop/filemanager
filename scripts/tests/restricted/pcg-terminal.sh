#!/bin/bash
set -euo pipefail
export HOME=/home/restricted PATH=/usr/bin:/bin
project="$HOME/project with spaces"
mkdir -p "$project/scripts" "$project/.runtime/supervision" "$HOME/.local/bin"
cp /repo/scripts/{runtime-exec.sh,terminal-command.sh,terminal-profile.sh} "$project/scripts/"
cp /runtime/php "$project/pcg"
cp /runtime/php "$HOME/.local/bin/pcg"
cp /runtime/php /dev/shm/pcg
chmod 700 "$project/pcg" "$HOME/.local/bin/pcg" /dev/shm/pcg
printf '/dev/shm/pcg\n' > "$project/.runtime/supervision/php"
cd "$project"
id
findmnt -T /home -no TARGET,OPTIONS
findmnt -T /tmp -no TARGET,OPTIONS
findmnt -T /dev/shm -no TARGET,OPTIONS
if [ -f /before/runtime-exec.sh ]; then
    /bin/bash /before/runtime-exec.sh php "$project" > before.out 2>before.err
    grep -q 'status real do processo 126' before.err
    printf 'PASS baseline: selected valid temporary PHP, but first rejected noexec home sources\n'
fi
/bin/bash --rcfile "$project/scripts/terminal-profile.sh" -ic '
set -eu
pcg -v >version.out 2>version.err
grep -q "PHP 8" version.out
! grep -E "Permission denied|PHP rejeitado" version.err
[ "$(pcg -r '\''echo PHP_BINARY;'\'')" = /dev/shm/pcg ]
printf "PASS terminal: configured executable reused, without rejected home probes\n"
source "$FILEMANAGER_ROOT/scripts/runtime-exec.sh"
fm_php_probe /dev/shm/pcg '\''echo "WARNING: startup fixture\nFM_PHP_RUNTIME_VALID\n";'\'' 2>warning.err
grep -q "WARNING: startup fixture" warning.err
if fm_php_probe /dev/shm/pcg '\''echo "FM_PHP_RUNTIME_VALID\n"; exit(42);'\'' 2>failed.err; then exit 1; fi
grep -q "status real do processo 42" failed.err
status=0; pcg -r "exit(23);" || status=$?
[ "$status" = 23 ]
printf "PASS diagnostics: warnings and real process exit codes remain visible\n"
# A matching, executable, non-PHP ELF must still fail feature validation.
mkdir -p "$FILEMANAGER_ROOT/invalid"
cp /bin/true "$FILEMANAGER_ROOT/invalid/pcg"
cp /bin/true /dev/shm/invalid-pcg
mkdir -p "$FILEMANAGER_ROOT/invalid/.runtime/supervision"
# Use a pcg basename so this is an eligible saved candidate.
bad=$(mktemp -d /dev/shm/fm-pcg-invalid.XXXXXXXX)
mv /dev/shm/invalid-pcg "$bad/pcg"
printf "%s\n" "$bad/pcg" > "$FILEMANAGER_ROOT/invalid/.runtime/supervision/php"
fm_php_candidates() { printf "%s\n" "$bad/pcg"; }
if fm_php "$FILEMANAGER_ROOT/invalid" >invalid.out 2>invalid.err; then exit 1; fi
grep -q "resposta do teste ausente" invalid.err
printf "PASS validation: status-zero ELF without PHP marker remains rejected\n"
rm -rf -- "$bad"
# The command runs a fresh resolver, unaffected by the test discovery override.
rm /dev/shm/pcg
recovered=$(pcg -r '\''echo PHP_BINARY;'\'' 2>recovery.err)
case "$recovered" in /dev/shm/filemanager-*/pcg) ;; *) echo "$recovered"; exit 1;; esac
cmp "$FILEMANAGER_ROOT/pcg" "$recovered"
! grep -E "Permission denied|PHP rejeitado" recovery.err
pcg -v >reused.out 2>reused.err
! grep -E "Permission denied|PHP rejeitado" reused.err
printf "PASS recovery: removed temporary PCG rebuilt with matching SHA-256 and reused\n"
'
