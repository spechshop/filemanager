#!/bin/bash
# Run on an executable filesystem, both as root and as an ordinary account.
set -euo pipefail
repo=${FILEMANAGER_TEST_ROOT:-/repo}
php_source=${SECURITY_PHP_BINARY:-/runtime/php}
node_root=${FILEMANAGER_TEST_NODE:-/node}
fixture=$(mktemp -d /tmp/fm-terminal-native.XXXXXXXX)
trap 'rm -rf -- "$fixture"' EXIT
project="$fixture/project with spaces"
mkdir -p "$project/scripts" "$project/.runtime/supervision" "$project/node_modules/.bin" "$fixture/account" "$fixture/original" "$fixture/old/bin"
cp "$repo/scripts/"{runtime-exec,terminal-command,terminal-profile}.sh "$project/scripts/"
cp "$php_source" "$project/pcg"
chmod 700 "$project/pcg"
ln -s "$node_root" "$project/.runtime/node"
printf '%s\n' "$fixture/old/bin" > "$project/.runtime/terminal-bin.path"
printf '#!/bin/bash\nexit 99\n' > "$fixture/old/bin/node"
chmod 700 "$fixture/old/bin/node"
for name in php pcg node npm npx composer fm-native-cli; do
    printf '#!/bin/bash\n[ "${1:-}" != fail ] || exit 23\nprintf "%%s\\n" "$0" "$@"\n' > "$fixture/original/$name"
    chmod 700 "$fixture/original/$name"
done
cp "$fixture/original/fm-native-cli" "$project/node_modules/.bin/fm-native-cli"
export FILEMANAGER_TEST_FIXTURE="$fixture"
env -u FILEMANAGER_TERMINAL_BASE_PATH HOME="$fixture/account" PATH="$fixture/original:/usr/bin:/bin" npm_config_script_shell=/bin/sh \
    /bin/bash --rcfile "$project/scripts/terminal-profile.sh" -ic '
set -eu
for name in php pcg node npm npx composer fm-native-cli; do
    expected="$FILEMANAGER_TEST_FIXTURE/original/$name"
    [ "$(command -v "$name")" = "$expected" ]
    [ "$("$name" "argument with spaces")" = "$(printf "%s\n" "$expected" "argument with spaces")" ]
    status=0; "$name" fail || status=$?
    [ "$status" = 23 ]
done
/bin/sh -c '\''[ "$(command -v php)" = "$FILEMANAGER_TEST_FIXTURE/original/php" ]'\''
filemanager_refresh_commands
[ "$npm_config_script_shell" = /bin/sh ]
[[ "$PATH" != *"$FILEMANAGER_TEST_FIXTURE/old/bin"* && "$PATH" != *"/dev/shm/"* ]]
[ ! -e "$FILEMANAGER_ROOT/.runtime/terminal-bin.lock" ]
printf "PASS native commands: paths, arguments, status and child shells preserved (UID %s)\n" "$EUID"
'

# Without system runtimes, expose executable managed files in place. Aliases
# for PHP/PCG may live in the project but must execute the original ELF.
env -u FILEMANAGER_TERMINAL_BASE_PATH -u npm_config_script_shell HOME="$fixture/account" PATH=/usr/bin:/bin \
    /bin/bash --rcfile "$project/scripts/terminal-profile.sh" -ic '
set -eu
[ "$(command -v node)" = "$FILEMANAGER_ROOT/.runtime/node/bin/node" ]
[ "$(command -v npm)" = "$FILEMANAGER_ROOT/.runtime/node/bin/npm" ]
[ "$(command -v npx)" = "$FILEMANAGER_ROOT/.runtime/node/bin/npx" ]
[ "$(command -v fm-native-cli)" = "$FILEMANAGER_ROOT/node_modules/.bin/fm-native-cli" ]
[ "$(php -r '\''echo PHP_BINARY;'\'')" = "$FILEMANAGER_ROOT/pcg" ]
[ "$(pcg -r '\''echo PHP_BINARY;'\'')" = "$FILEMANAGER_ROOT/pcg" ]
[[ "$(command -v php)" = "$FILEMANAGER_ROOT/.runtime/"* ]]
[ -z "${npm_config_script_shell:-}" ]
cd "$FILEMANAGER_ROOT"
mkdir fixture-package
printf '\''{"name":"fm-native-package","version":"1.0.0","bin":{"fm-native-package":"cli.js"}}\n'\'' > fixture-package/package.json
printf '\''#!/usr/bin/env node\nconsole.log("CLI:" + process.argv.slice(2).join("|"));\n'\'' > fixture-package/cli.js
chmod 700 fixture-package/cli.js
npm install -g ./fixture-package --offline --ignore-scripts --no-audit --no-fund
[ "$(command -v fm-native-package)" = "$FILEMANAGER_ROOT/.runtime/npm-global/bin/fm-native-package" ]
fm-native-package "with spaces" | grep -Fx "CLI:with spaces"
printf '\''{"name":"native-test","version":"1.0.0","scripts":{"probe":"fm-native-package local"}}\n'\'' > package.json
npm install ./fixture-package --offline --ignore-scripts --no-audit --no-fund
npm run probe | grep -Fx CLI:local
npx --no-install fm-native-package npx | grep -Fx CLI:npx
[[ "$PATH" != *"/dev/shm/"* ]]
printf "PASS executable managed runtimes and npm commands remain at their original locations\n"

# A permission-only shell fallback also belongs in the executable project.
chmod 600 node_modules/.bin/fm-native-cli
filemanager_refresh_commands
[[ "$(command -v fm-native-cli)" = "$FILEMANAGER_ROOT/.runtime/"* ]]
fm-native-cli "permission fallback" | grep -Fx "permission fallback"
[[ "$npm_config_script_shell" = "$FILEMANAGER_ROOT/.runtime/"* ]]
chmod 700 node_modules/.bin/fm-native-cli
filemanager_refresh_commands
[ "$(command -v fm-native-cli)" = "$FILEMANAGER_ROOT/node_modules/.bin/fm-native-cli" ]
[ -z "${npm_config_script_shell:-}" ]
printf "PASS local fallback and recovery preserve the original command\n"
'

cp "$project/pcg" "$fixture/old/pcg"
printf '%s\n' "$fixture/old/pcg" > "$project/.runtime/supervision/php"
env -u FILEMANAGER_TERMINAL_BASE_PATH HOME="$fixture/account" PATH=/usr/bin:/bin \
    /bin/bash -eu -c '
source "$1/scripts/runtime-exec.sh"
original=$(fm_php "$1")
[ "$original" = "$1/pcg" ]
[ "$(fm_prepare_binary "$1/pcg" "$1" -v)" = "$1/pcg" ]
printf "PASS persistent PHP wins over a saved executable cache\n"
' bash "$project"
