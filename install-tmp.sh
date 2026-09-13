#!/usr/bin/env bash

# Instalação dedicada e sem Git em /tmp/filemanager. O diretório pode ser
# alterado por FILEMANAGER_TMP_DIR, desde que continue abaixo de /tmp.

set -uo pipefail

# Mesmo quando chamado como `bash install-tmp.sh`, deixe a cópia baixada pronta
# para as próximas execuções diretas.
chmod +x "$0" 2>/dev/null || {
    printf '[installer-tmp][erro] Não foi possível aplicar chmod +x em %s.\n' "$0" >&2
    exit 1
}

INSTALL_DIR="${FILEMANAGER_TMP_DIR:-/tmp/filemanager}"
ARCHIVE_URL="${FILEMANAGER_ARCHIVE_URL:-https://codeload.github.com/spechshop/filemanager/tar.gz/refs/heads/newterm}"
WORK_DIR=""

log()  { printf '\033[1;34m[installer-tmp]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[installer-tmp][ok]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[installer-tmp][aviso]\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[1;31m[installer-tmp][erro]\033[0m %s\n' "$*" >&2; }

cleanup() {
    [ -n "$WORK_DIR" ] && [ -d "$WORK_DIR" ] && rm -rf -- "$WORK_DIR"
}
trap cleanup EXIT HUP INT TERM

case "$INSTALL_DIR" in
    /tmp/*) ;;
    *)
        err "O destino deve estar abaixo de /tmp: $INSTALL_DIR"
        exit 64
        ;;
esac
case "/${INSTALL_DIR#/tmp/}/" in
    */../*|*/./*)
        err "O destino contém segmentos de caminho inválidos: $INSTALL_DIR"
        exit 64
        ;;
esac

download() {
    local url="$1" output="$2"

    if command -v wget >/dev/null 2>&1; then
        wget --no-check-certificate --tries=3 --output-document="$output" "$url" \
            && return 0
        warn "wget falhou; repetindo com curl quando disponível."
    fi
    if command -v curl >/dev/null 2>&1; then
        curl -k --fail --location --retry 3 --retry-delay 2 --output "$output" "$url" \
            && return 0
    fi
    return 1
}

ensure_executable_permissions() {
    local root="$1" executable

    for executable in \
        "$root/install-tmp.sh" \
        "$root/installer.sh" \
        "$root/filemanagerctl" \
        "$root/scripts/install-codex.sh" \
        "$root/scripts/install-codex-native.sh" \
        "$root/pcg" \
        "$root/composer" \
        "$root/.runtime/node/bin/node" \
        "$root/.runtime/node/bin/npm" \
        "$root/.runtime/node/bin/npx" \
        "$root/.runtime/node/bin/corepack" \
        "$root/.runtime/codex/bin/codex" \
        "$root/.runtime/micromamba/bin/micromamba" \
        "$root/.runtime/native-toolchain/bin/python" \
        "$root/.runtime/native-toolchain/bin/python3" \
        "$root/.runtime/native-toolchain/bin/make" \
        "$root/.runtime/native-toolchain/bin/"*-gcc \
        "$root/.runtime/native-toolchain/bin/"*-g++
    do
        [ -e "$executable" ] || [ -L "$executable" ] || continue
        chmod +x "$executable" || {
            err "Não foi possível garantir permissão de execução: $executable"
            return 1
        }
    done
}

if [ -f "$INSTALL_DIR/server.php" ] \
    && [ -f "$INSTALL_DIR/composer.json" ] \
    && [ -f "$INSTALL_DIR/installer.sh" ]; then
    log "Instalação existente encontrada em $INSTALL_DIR; reutilizando."
elif [ -e "$INSTALL_DIR" ]; then
    err "O destino já existe, mas não contém uma instalação válida: $INSTALL_DIR"
    exit 1
else
    command -v tar >/dev/null 2>&1 || {
        err "O comando tar é necessário para instalar em /tmp."
        exit 1
    }
    WORK_DIR="$(mktemp -d /tmp/filemanager-bootstrap.XXXXXX)" || {
        err "Não foi possível criar o diretório temporário de preparação."
        exit 1
    }
    ARCHIVE="$WORK_DIR/filemanager.tar.gz"
    log "Baixando o FileManager para $INSTALL_DIR..."
    download "$ARCHIVE_URL" "$ARCHIVE" || {
        err "Não foi possível baixar o código do FileManager."
        exit 1
    }
    mkdir -p "$WORK_DIR/source" || exit 1
    tar -xzf "$ARCHIVE" --strip-components=1 -C "$WORK_DIR/source" || {
        err "Não foi possível extrair o código do FileManager."
        exit 1
    }
    if [ ! -f "$WORK_DIR/source/server.php" ] \
        || [ ! -f "$WORK_DIR/source/composer.json" ] \
        || [ ! -f "$WORK_DIR/source/installer.sh" ]; then
        err "O arquivo baixado não contém uma instalação válida do FileManager."
        exit 1
    fi
    mv -- "$WORK_DIR/source" "$INSTALL_DIR" || {
        err "Não foi possível criar $INSTALL_DIR."
        exit 1
    }
    ok "Código extraído em $INSTALL_DIR."
fi

ensure_executable_permissions "$INSTALL_DIR" || exit 1

log "Executando o instalador principal em $INSTALL_DIR..."
(
    cd "$INSTALL_DIR" || exit 1
    chmod +x ./installer.sh ./filemanagerctl \
        ./scripts/install-codex.sh ./scripts/install-codex-native.sh 2>/dev/null
    bash ./installer.sh
)
INSTALL_STATUS=$?

# Downloads e atualizações feitos pelo instalador podem criar novos arquivos.
# Reaplicamos chmod para garantir que todos estejam executáveis ao terminar.
ensure_executable_permissions "$INSTALL_DIR" || exit 1

if [ "$INSTALL_STATUS" -ne 0 ]; then
    err "O instalador principal terminou com código $INSTALL_STATUS."
    exit "$INSTALL_STATUS"
fi

ok "FileManager instalado em $INSTALL_DIR."
printf 'Controle: bash %s/filemanagerctl status\n' "$INSTALL_DIR"
