#!/bin/bash
set -euo pipefail
export HOME=/home/restricted
project="$HOME/project with spaces"
mkdir -p "$project/scripts" "$project/.runtime" "$project/files" /dev/shm/fm-terminal-tests
cp /repo/scripts/{terminal-profile.sh,terminal-command.sh,runtime-exec.sh} "$project/scripts/"
cp -a /node "$project/.runtime/node"
export FILEMANAGER_EXEC_DIR=/dev/shm/fm-terminal-tests
export PATH=/usr/bin:/bin
printf 'export FM_ACCOUNT_PROFILE=loaded\n' > "$HOME/.bashrc"
printf 'export FM_LOCAL_PROFILE=loaded\n' > "$project/.filemanager-profile"
cd "$project"
printf 'Mounts and user:\n'
id
findmnt -T /home -no TARGET,OPTIONS
findmnt -T /tmp -no TARGET,OPTIONS
findmnt -T /dev/shm -no TARGET,OPTIONS
if "$project/.runtime/node/bin/node" --version; then echo 'Expected noexec rejection'; exit 1; fi
# Run the actual rcfile in the same interactive Bash invocation as both PTYs.
PATH=/missing /bin/bash --rcfile "$project/scripts/terminal-profile.sh" -ic '
set -eu
[ "$FM_ACCOUNT_PROFILE" = loaded ] && [ "$FM_LOCAL_PROFILE" = loaded ]
node --version
npm --version
npx --version
/bin/sh -c '\''node -e "console.log(\"child PATH works\")"'\''
mkdir -p fixture-package
printf '\''{"name":"fm-profile-probe","version":"1.0.0","bin":{"fm-profile-probe":"cli.js"}}\n'\'' > fixture-package/package.json
printf '\''#!/usr/bin/env node\nconsole.log("CLI:" + process.argv.slice(2).join("|"));\n'\'' > fixture-package/cli.js
npm install -g ./fixture-package --offline --ignore-scripts --no-audit --no-fund && fm-profile-probe "argument with spaces" | grep -Fx "CLI:argument with spaces"
printf '\''{"name":"terminal-test","version":"1.0.0","scripts":{"probe":"fm-profile-probe local"}}\n'\'' > package.json
npm install ./fixture-package --offline --ignore-scripts --no-audit --no-fund
npm run probe | grep -Fx CLI:local
npx --no-install fm-profile-probe npx | grep -Fx CLI:npx
# Preserve the actual package at its original location, not a lone npm copy.
[ -f "$FILEMANAGER_ROOT/.runtime/node/lib/node_modules/npm/lib/cli.js" ]
runtime=$(node -p process.execPath)
case "$runtime" in /dev/shm/fm-terminal-tests/*/node) ;; *) echo "Unexpected runtime: $runtime"; exit 1;; esac
rm -- "$runtime"
node --version
printf "PASS: removed temporary node recovered automatically\n"
bin=$(dirname "$(command -v npm)")
rm -rf -- "$bin"
filemanager_refresh_commands
npm --version
fm-profile-probe recovered | grep -Fx CLI:recovered
if npm run missing-script; then echo "Expected npm failure"; exit 1; fi
npm uninstall -g fm-profile-probe --offline --ignore-scripts --no-audit --no-fund
npm uninstall fm-profile-probe --offline --ignore-scripts --no-audit --no-fund
filemanager_refresh_commands
if command -v fm-profile-probe; then echo "Uninstalled command still exposed"; exit 1; fi
printf "PASS: runtime recovery, npm/npx, global/local CLI, npm scripts, custom profile and failure status\n"
'
