#!/usr/bin/env bash

# Instala um runtime Node compatível, as dependências do FileManager e o
# Codex CLI sem substituir à força o Node.js usado pelo restante do servidor.

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" 2>/dev/null && pwd -P)"
PROJECT_ROOT="${1:-$(cd -- "$SCRIPT_DIR/.." 2>/dev/null && pwd -P)}"
NODE_TARGET="${2:-preserve}"
RUNTIME_DIR="$PROJECT_ROOT/.runtime"
MANAGED_NODE_DIR="$RUNTIME_DIR/node"
CODEX_PREFIX="$RUNTIME_DIR/codex"
STATE_FILE="$RUNTIME_DIR/codex-installer.json"
AUDIT_FILE="$RUNTIME_DIR/npm-audit.json"
LOCK_FILE="$RUNTIME_DIR/codex-installer.lock"
MIN_NODE_MAJOR=22
DEFAULT_MANAGED_NODE_MAJOR=24

case "$NODE_TARGET" in
    preserve|22|24|26) ;;
    *)
        printf '[Codex][erro] Versão Node.js inválida: %s\n' "$NODE_TARGET" >&2
        exit 64
        ;;
esac

mkdir -p "$RUNTIME_DIR"

json_state() {
    local status="$1" message="$2" exit_code="${3:-null}" tmp escaped_message
    tmp="$STATE_FILE.tmp.$$"
    escaped_message="$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g')"
    printf '{"status":"%s","message":"%s","pid":%s,"updatedAt":"%s","exitCode":%s}\n' \
        "$status" "$escaped_message" "$$" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$exit_code" > "$tmp"
    mv -f -- "$tmp" "$STATE_FILE"
}

log()  { printf '[Codex] %s\n' "$*"; }
ok()   { printf '[Codex][ok] %s\n' "$*"; }
fail() { printf '[Codex][erro] %s\n' "$*" >&2; }

LAST_MESSAGE="Instalação interrompida. Consulte o log para ver o motivo."
finish() {
    local code=$?
    if [ "$code" -eq 0 ]; then
        json_state success "Node.js, dependências e Codex CLI estão prontos." 0
    else
        json_state failed "$LAST_MESSAGE" "$code"
    fi
}
trap finish EXIT

if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        fail "Já existe uma instalação do Codex em andamento."
        trap - EXIT
        exit 75
    fi
fi

json_state running "Verificando o ambiente..." null

download() {
    local url="$1" output="$2"
    if command -v curl >/dev/null 2>&1; then
        curl --fail --location --retry 3 --retry-delay 2 --output "$output" "$url"
        return $?
    fi
    if command -v wget >/dev/null 2>&1; then
        wget --quiet --tries=3 --output-document="$output" "$url"
        return $?
    fi
    return 127
}

node_major() {
    "$1" --version 2>/dev/null | sed -n 's/^v\([0-9][0-9]*\).*/\1/p'
}

NODE_BIN=""
NPM_CLI=""

if [ "$NODE_TARGET" = "preserve" ]; then
    if [ -x "$MANAGED_NODE_DIR/bin/node" ] \
        && [ "$(node_major "$MANAGED_NODE_DIR/bin/node")" -ge "$MIN_NODE_MAJOR" ] 2>/dev/null; then
        NODE_BIN="$MANAGED_NODE_DIR/bin/node"
    elif command -v node >/dev/null 2>&1 \
        && [ "$(node_major "$(command -v node)")" -ge "$MIN_NODE_MAJOR" ] 2>/dev/null; then
        NODE_BIN="$(command -v node)"
    fi
fi

install_managed_node() {
    local target_major="$1" machine platform release_path sums archive filename checksum temp_dir extracted
    machine="$(uname -m 2>/dev/null)"
    platform="$(uname -s 2>/dev/null | tr '[:upper:]' '[:lower:]')"
    case "$machine" in
        x86_64|amd64) machine="x64" ;;
        aarch64|arm64) machine="arm64" ;;
        armv7l) machine="armv7l" ;;
        *) LAST_MESSAGE="Arquitetura $machine não suportada pelo instalador automático."; return 1 ;;
    esac
    if [ "$platform" != "linux" ]; then
        LAST_MESSAGE="O instalador automático de Node.js suporta Linux; sistema detectado: $platform."
        return 1
    fi
    command -v tar >/dev/null 2>&1 || {
        LAST_MESSAGE="O comando tar é necessário para instalar o Node.js gerenciado."
        return 1
    }

    temp_dir="$(mktemp -d "$RUNTIME_DIR/node-install.XXXXXX")" || return 1
    release_path="latest-v${target_major}.x"
    sums="$temp_dir/SHASUMS256.txt"
    json_state running "Baixando a versão mais recente do Node.js ${target_major}..." null
    log "Preparando o runtime gerenciado Node.js ${target_major}."
    if ! download "https://nodejs.org/dist/$release_path/SHASUMS256.txt" "$sums"; then
        LAST_MESSAGE="Não foi possível obter a lista oficial de versões do Node.js."
        rm -rf -- "$temp_dir"
        return 1
    fi
    filename="$(awk -v suffix="linux-$machine.tar.gz" '$2 ~ suffix "$" { print $2; exit }' "$sums")"
    checksum="$(awk -v file="$filename" '$2 == file { print $1; exit }' "$sums")"
    if [ -z "$filename" ] || [ -z "$checksum" ]; then
        LAST_MESSAGE="Não foi encontrado um pacote Node.js ${target_major} para esta arquitetura."
        rm -rf -- "$temp_dir"
        return 1
    fi
    archive="$temp_dir/$filename"
    if ! download "https://nodejs.org/dist/$release_path/$filename" "$archive"; then
        LAST_MESSAGE="Falha ao baixar o Node.js ${target_major}."
        rm -rf -- "$temp_dir"
        return 1
    fi
    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s  %s\n' "$checksum" "$archive" | sha256sum --check --status || {
            LAST_MESSAGE="O checksum do pacote Node.js não confere."
            rm -rf -- "$temp_dir"
            return 1
        }
    else
        LAST_MESSAGE="sha256sum é necessário para validar o download do Node.js."
        rm -rf -- "$temp_dir"
        return 1
    fi
    if ! tar -xzf "$archive" -C "$temp_dir"; then
        LAST_MESSAGE="Não foi possível extrair o pacote Node.js."
        rm -rf -- "$temp_dir"
        return 1
    fi
    extracted="$temp_dir/${filename%.tar.gz}"
    if [ ! -x "$extracted/bin/node" ]; then
        LAST_MESSAGE="O pacote Node.js baixado não contém um executável válido."
        rm -rf -- "$temp_dir"
        return 1
    fi
    rm -rf -- "$MANAGED_NODE_DIR"
    mv -- "$extracted" "$MANAGED_NODE_DIR"
    rm -rf -- "$temp_dir"
    NODE_BIN="$MANAGED_NODE_DIR/bin/node"
    ok "Node.js $($NODE_BIN --version) instalado em .runtime/node."
}

if [ "$NODE_TARGET" != "preserve" ]; then
    install_managed_node "$NODE_TARGET" || exit 1
elif [ -z "$NODE_BIN" ]; then
    install_managed_node "$DEFAULT_MANAGED_NODE_MAJOR" || exit 1
else
    ok "Usando $NODE_BIN ($($NODE_BIN --version))."
fi

export PATH="$(dirname -- "$NODE_BIN"):$CODEX_PREFIX/bin:${PATH:-}"

if [ -f "$(dirname -- "$NODE_BIN")/../lib/node_modules/npm/bin/npm-cli.js" ]; then
    NPM_CLI="$(dirname -- "$NODE_BIN")/../lib/node_modules/npm/bin/npm-cli.js"
elif command -v npm >/dev/null 2>&1; then
    NPM_CLI="$(command -v npm)"
else
    LAST_MESSAGE="npm não foi encontrado no runtime Node.js selecionado."
    exit 1
fi

run_npm() {
    if [[ "$NPM_CLI" == *.js ]]; then
        "$NODE_BIN" "$NPM_CLI" "$@"
    else
        "$NPM_CLI" "$@"
    fi
}

json_state running "Instalando as dependências do File Manager..." null
log "Instalando dependências com Node.js $($NODE_BIN --version)..."
if ! (cd "$PROJECT_ROOT" && run_npm install --no-audit --no-fund); then
    LAST_MESSAGE="npm install falhou. Consulte o log do instalador."
    exit 1
fi

json_state running "Instalando o Codex CLI oficial..." null
log "Instalando @openai/codex no runtime gerenciado..."
mkdir -p "$CODEX_PREFIX"
if ! run_npm install --global --prefix "$CODEX_PREFIX" --no-audit --no-fund @openai/codex@latest; then
    LAST_MESSAGE="A instalação do pacote oficial @openai/codex falhou."
    exit 1
fi

CODEX_BIN="$CODEX_PREFIX/bin/codex"
if [ ! -x "$CODEX_BIN" ]; then
    LAST_MESSAGE="O Codex CLI foi instalado, mas o executável não foi encontrado."
    exit 1
fi
ok "$($CODEX_BIN --version 2>/dev/null) instalado em .runtime/codex."

# Torna o comando conveniente para o mesmo usuário, sem sobrescrever um
# executável real que já esteja instalado fora do FileManager.
USER_LOCAL_BIN="${HOME:-}/.local/bin"
if [ -n "${HOME:-}" ]; then
    mkdir -p "$USER_LOCAL_BIN" 2>/dev/null || true
    if [ ! -e "$USER_LOCAL_BIN/codex" ] || [ -L "$USER_LOCAL_BIN/codex" ]; then
        ln -sfn "$CODEX_BIN" "$USER_LOCAL_BIN/codex" 2>/dev/null || true
    fi
fi

json_state running "Verificando vulnerabilidades npm..." null
log "Executando npm audit (o resultado não bloqueia a instalação)..."
(cd "$PROJECT_ROOT" && run_npm audit --omit=dev --json > "$AUDIT_FILE.tmp" 2>/dev/null)
if [ -s "$AUDIT_FILE.tmp" ]; then
    mv -f -- "$AUDIT_FILE.tmp" "$AUDIT_FILE"
else
    rm -f -- "$AUDIT_FILE.tmp"
fi

LAST_MESSAGE="Node.js, dependências e Codex CLI estão prontos."
exit 0
