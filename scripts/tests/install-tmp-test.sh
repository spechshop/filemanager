#!/usr/bin/env bash

set -euo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
TMP_INSTALLER="$TEST_DIR/../../install-tmp.sh"
TEST_ROOT="$(mktemp -d /tmp/filemanager-tmp-test.XXXXXX)"
SOURCE_ROOT="$TEST_ROOT/archive/filemanager-test"
ARCHIVE="$TEST_ROOT/filemanager.tar.gz"
DESTINATION="$TEST_ROOT/installed"

cleanup() {
    rm -rf -- "$TEST_ROOT"
}
trap cleanup EXIT

mkdir -p "$SOURCE_ROOT/scripts"
touch "$SOURCE_ROOT/server.php" "$SOURCE_ROOT/composer.json"
cat > "$SOURCE_ROOT/installer.sh" <<'EOF'
#!/usr/bin/env bash
set -eu
touch .installer-ran pcg composer
mkdir -p .runtime/node/bin .runtime/codex/bin .runtime/micromamba/bin
touch .runtime/node/bin/node .runtime/node/bin/npm .runtime/node/bin/npx
touch .runtime/node/bin/corepack .runtime/codex/bin/codex
touch .runtime/micromamba/bin/micromamba
EOF
printf '%s\n' '#!/usr/bin/env bash' > "$SOURCE_ROOT/filemanagerctl"
printf '%s\n' '#!/usr/bin/env bash' > "$SOURCE_ROOT/scripts/install-codex.sh"
printf '%s\n' '#!/usr/bin/env bash' > "$SOURCE_ROOT/scripts/install-codex-native.sh"
chmod 644 "$SOURCE_ROOT/installer.sh" "$SOURCE_ROOT/filemanagerctl" "$SOURCE_ROOT/scripts/"*.sh
tar -czf "$ARCHIVE" -C "$TEST_ROOT/archive" filemanager-test

FILEMANAGER_TMP_DIR="$DESTINATION" \
FILEMANAGER_ARCHIVE_URL="file://$ARCHIVE" \
    bash "$TMP_INSTALLER" >/dev/null 2>&1

for executable in \
    installer.sh filemanagerctl scripts/install-codex.sh scripts/install-codex-native.sh \
    pcg composer .runtime/node/bin/node .runtime/node/bin/npm \
    .runtime/node/bin/npx .runtime/node/bin/corepack .runtime/codex/bin/codex \
    .runtime/micromamba/bin/micromamba
do
    [ -x "$DESTINATION/$executable" ] || {
        printf '[teste][erro] sem permissão de execução: %s\n' "$executable" >&2
        exit 1
    }
done
[ -f "$DESTINATION/.installer-ran" ]

# Uma segunda execução deve reutilizar a instalação válida.
FILEMANAGER_TMP_DIR="$DESTINATION" \
FILEMANAGER_ARCHIVE_URL="file://$ARCHIVE" \
    bash "$TMP_INSTALLER" >/dev/null 2>&1

printf '[teste][ok] instalação dedicada em /tmp e chmod validados.\n'
