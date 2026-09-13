#!/usr/bin/env bash

set -uo pipefail

TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
INSTALLER="$TEST_DIR/../../installer.sh"
TEST_ROOT="$(mktemp -d)"
PREFIX="$TEST_ROOT/installer-prefix.sh"

cleanup() {
    rm -rf -- "$TEST_ROOT"
}
trap cleanup EXIT

# Executa somente a preparação inicial do ambiente. O restante do instalador
# possui downloads e serviços que não pertencem a este teste de regressão.
awk '/^# 3\) Detecção do gerenciador de pacotes/ { exit } { print }' "$INSTALLER" > "$PREFIX"
cat >> "$PREFIX" <<'EOF'
printf 'HOME=%s\n' "$HOME"
printf 'XDG_CONFIG_HOME=%s\n' "$XDG_CONFIG_HOME"
printf 'XDG_CACHE_HOME=%s\n' "$XDG_CACHE_HOME"
printf 'LOCAL_BIN=%s\n' "$LOCAL_BIN"
EOF

EXPECTED_HOME="$(getent passwd "$(id -un)" 2>/dev/null | awk -F: 'NR == 1 { print $6 }')"
if [ -z "$EXPECTED_HOME" ]; then
    EXPECTED_HOME="$(awk -F: -v account="$(id -un)" '$1 == account { print $6; exit }' /etc/passwd)"
fi

OUTPUT="$(env \
    HOME=/root \
    XDG_CONFIG_HOME=/root/.config \
    XDG_CACHE_HOME=/root/.cache \
    bash "$PREFIX" 2>/dev/null)"

grep -Fxq "HOME=$EXPECTED_HOME" <<< "$OUTPUT"
grep -Fxq "XDG_CONFIG_HOME=$EXPECTED_HOME/.config" <<< "$OUTPUT"
grep -Fxq "XDG_CACHE_HOME=$EXPECTED_HOME/.cache" <<< "$OUTPUT"
grep -Fxq "LOCAL_BIN=$EXPECTED_HOME/.local/bin" <<< "$OUTPUT"

printf '[teste][ok] HOME e diretórios XDG são corrigidos para a conta efetiva.\n'
