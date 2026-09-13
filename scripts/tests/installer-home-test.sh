#!/usr/bin/env bash

set -uo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
INSTALLER="$TEST_DIR/../../installer.sh"
CODEX_INSTALLER="$TEST_DIR/../install-codex.sh"
TEST_ROOT="$(mktemp -d)"
PREFIX="$TEST_ROOT/installer-prefix.sh"
CODEX_PREFIX="$TEST_ROOT/codex-installer-prefix.sh"
FAKE_BIN="$TEST_ROOT/bin"
EXPECTED_HOME="$TEST_ROOT/account-home"

cleanup() {
    rm -rf -- "$TEST_ROOT"
}
trap cleanup EXIT

mkdir -p "$FAKE_BIN"
cat > "$FAKE_BIN/id" <<'EOF'
#!/bin/sh
case "$1" in
    -u) printf '12345\n' ;;
    -un) printf 'shared-user\n' ;;
    *) exit 1 ;;
esac
EOF
cat > "$FAKE_BIN/getent" <<EOF
#!/bin/sh
printf 'shared-user:x:12345:12345::%s:/bin/sh\n' '$EXPECTED_HOME'
EOF
cat > "$FAKE_BIN/sudo" <<'EOF'
#!/bin/sh
exit 1
EOF
chmod +x "$FAKE_BIN/id" "$FAKE_BIN/getent" "$FAKE_BIN/sudo"

# Executa somente a preparação inicial do ambiente. O restante do instalador
# possui downloads e serviços que não pertencem a este teste de regressão.
awk '/^# 3\) Detecção do gerenciador de pacotes/ { exit } { print }' "$INSTALLER" > "$PREFIX"
cat >> "$PREFIX" <<'EOF'
printf 'HOME=%s\n' "$HOME"
printf 'XDG_CONFIG_HOME=%s\n' "$XDG_CONFIG_HOME"
printf 'XDG_CACHE_HOME=%s\n' "$XDG_CACHE_HOME"
printf 'LOCAL_BIN=%s\n' "$LOCAL_BIN"
EOF

OUTPUT="$(env \
    HOME=/root \
    XDG_CONFIG_HOME=/proc/filemanager-config \
    XDG_CACHE_HOME=/proc/filemanager-cache \
    PATH="$FAKE_BIN:$PATH" \
    bash "$PREFIX" 2>/dev/null)"

grep -Fxq "HOME=$EXPECTED_HOME" <<< "$OUTPUT"
grep -Fxq "XDG_CONFIG_HOME=$EXPECTED_HOME/.config" <<< "$OUTPUT"
grep -Fxq "XDG_CACHE_HOME=$EXPECTED_HOME/.cache" <<< "$OUTPUT"
grep -Fxq "LOCAL_BIN=$EXPECTED_HOME/.local/bin" <<< "$OUTPUT"

printf '[teste][ok] HOME e diretórios XDG são corrigidos para a conta efetiva.\n'

# O botão de reparo chama install-codex.sh diretamente, sem passar pelo
# installer.sh. Validamos a proteção própria desse segundo ponto de entrada.
awk '/^json_state\(\)/ { exit } { print }' "$CODEX_INSTALLER" > "$CODEX_PREFIX"
cat >> "$CODEX_PREFIX" <<'EOF'
printf 'HOME=%s\n' "$HOME"
printf 'XDG_CONFIG_HOME=%s\n' "$XDG_CONFIG_HOME"
printf 'XDG_CACHE_HOME=%s\n' "$XDG_CACHE_HOME"
EOF

REPAIR_ROOT="$TEST_ROOT/repair-project"
REPAIR_OUTPUT="$(env \
    HOME=/proc/filemanager-home \
    XDG_CONFIG_HOME=/proc/filemanager-config \
    XDG_CACHE_HOME=/proc/filemanager-cache \
    bash "$CODEX_PREFIX" "$REPAIR_ROOT" preserve 2>/dev/null)"

grep -Fxq "HOME=$REPAIR_ROOT/.runtime/user-home" <<< "$REPAIR_OUTPUT"
grep -Fxq "XDG_CONFIG_HOME=$REPAIR_ROOT/.runtime/user-home/.config" <<< "$REPAIR_OUTPUT"
grep -Fxq "XDG_CACHE_HOME=$REPAIR_ROOT/.runtime/user-home/.cache" <<< "$REPAIR_OUTPUT"

printf '[teste][ok] O reparador isola HOME e XDG herdados sem permissão.\n'
