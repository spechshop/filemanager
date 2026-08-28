#!/usr/bin/env bash
# =====================================================================
# installer.sh - Instalação resiliente do FileManager
# Objetivo: concluir a instalação 100% mesmo quando comandos que exigem
# privilégios (sudo/root) falham. Sempre tenta a melhor alternativa
# disponível (fallbacks em cascata) e NUNCA aborta por causa de uma
# falha isolada.
# =====================================================================

# Não usamos "set -e": queremos sobreviver a falhas e seguir com fallbacks.
set +e

# Toda a instalação deve poder rodar sem interação no terminal.
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------------
# Logs
# ---------------------------------------------------------------------
log()  { printf '\033[1;34m[installer]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[installer][ok]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[installer][aviso]\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[1;31m[installer][erro]\033[0m %s\n' "$*" >&2; }

# ---------------------------------------------------------------------
# 1) Detecção de privilégios: root direto, sudo funcional, ou usuário
# ---------------------------------------------------------------------
IS_ROOT=0
[ "$(id -u 2>/dev/null)" = "0" ] && IS_ROOT=1

SUDO=""
if [ "$IS_ROOT" -ne 1 ]; then
    if command -v sudo >/dev/null 2>&1; then
        if sudo -n true 2>/dev/null; then
            SUDO="sudo -n"
        else
            warn "sudo sem senha indisponível. Seguindo em modo usuário (sem perguntas)."
        fi
    else
        warn "Comando sudo não encontrado. Seguindo em modo usuário (sem root)."
    fi
fi

# run_priv: executa com privilégios quando possível; caso contrário tenta
# executar mesmo assim. Nunca aborta o script.
run_priv() {
    if [ "$IS_ROOT" -eq 1 ]; then
        "$@"
    elif [ -n "$SUDO" ]; then
        $SUDO "$@"
    else
        "$@"
    fi
}

# Usuário que executará o FileManager. Quando o instalador tiver sido chamado
# via sudo, preservamos o usuário original em vez de subir o serviço como root.
FILEMANAGER_USER="$(id -un 2>/dev/null)"
if [ "$IS_ROOT" -eq 1 ] && [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ] \
    && id "$SUDO_USER" >/dev/null 2>&1; then
    FILEMANAGER_USER="$SUDO_USER"
fi

# ---------------------------------------------------------------------
# 2) Diretório de binários do usuário (fallback quando não há root)
# ---------------------------------------------------------------------
export HOME="${HOME:-/root}"
LOCAL_BIN="$HOME/.local/bin"
mkdir -p "$LOCAL_BIN" 2>/dev/null
case ":$PATH:" in
    *":$LOCAL_BIN:"*) ;;
    *) export PATH="$LOCAL_BIN:$PATH" ;;
esac

# ---------------------------------------------------------------------
# 3) Detecção do gerenciador de pacotes
# ---------------------------------------------------------------------
PM=""
for pm in apt-get apt dnf yum pacman zypper apk brew; do
    if command -v "$pm" >/dev/null 2>&1; then PM="$pm"; break; fi
done
[ -n "$PM" ] && log "Gerenciador de pacotes detectado: $PM" || warn "Nenhum gerenciador de pacotes conhecido encontrado."

pkg_update() {
    [ -z "$PM" ] && return 0
    case "$PM" in
        apt|apt-get) run_priv "$PM" update -y  2>/dev/null ;;
        dnf|yum)     run_priv "$PM" makecache -y 2>/dev/null ;;
        pacman)      run_priv pacman -Sy --noconfirm 2>/dev/null ;;
        zypper)      run_priv zypper --non-interactive refresh 2>/dev/null ;;
        apk)         run_priv apk update 2>/dev/null ;;
        brew)        brew update 2>/dev/null ;;
    esac
    return 0
}

# pkg_install <pacote apt> [pacote dnf] ... aceita nome padrão; usa o mesmo
# nome para todos, mas permite variação via segundo argumento opcional.
pkg_install() {
    local pkg="$1"
    [ -z "$PM" ] && { warn "Sem gerenciador de pacotes; não foi possível instalar '$pkg'."; return 1; }
    case "$PM" in
        apt|apt-get) run_priv "$PM" install -y "$pkg" ;;
        dnf|yum)     run_priv "$PM" install -y "$pkg" ;;
        pacman)      run_priv pacman -S --noconfirm "$pkg" ;;
        zypper)      run_priv zypper --non-interactive install "$pkg" ;;
        apk)         run_priv apk add "$pkg" ;;
        brew)        brew install "$pkg" ;;
    esac
}

# ensure_cmd <comando> [pacote]: garante que um comando exista, instalando-o
# se necessário. Retorna 0 se disponível ao final, 1 caso contrário.
ensure_cmd() {
    local cmd="$1"; local pkg="${2:-$1}"
    if command -v "$cmd" >/dev/null 2>&1; then
        return 0
    fi
    log "Comando '$cmd' ausente. Tentando instalar via '$pkg'..."
    pkg_install "$pkg" >/dev/null 2>&1
    if command -v "$cmd" >/dev/null 2>&1; then
        ok "'$cmd' instalado."
        return 0
    fi
    warn "Não foi possível instalar '$cmd'. Seguindo com fallbacks quando aplicável."
    return 1
}

# ---------------------------------------------------------------------
# 4) Download resiliente (curl -> wget)
# ---------------------------------------------------------------------
download() {
    local url="$1"; local out="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -k -fL --retry 3 --retry-delay 2 -o "$out" "$url" && return 0
    fi
    if command -v wget >/dev/null 2>&1; then
        wget --no-check-certificate -q -t 3 -O "$out" "$url" && return 0
    fi
    # última tentativa: instalar curl e repetir
    ensure_cmd curl curl >/dev/null 2>&1
    if command -v curl >/dev/null 2>&1; then
        curl -k -fL --retry 3 -o "$out" "$url" && return 0
    fi
    return 1
}

# download_github_archive <owner/repo> <branch> <destination>
# Fallback para sistemas antigos onde o gerenciador de pacotes não consegue
# instalar Git. Extrai em diretório temporário e só então move para o destino.
download_github_archive() {
    local repository="$1" branch="$2" destination="$3"
    local repository_name archive_dir archive_file extracted_dir

    if ! command -v tar >/dev/null 2>&1; then
        ensure_cmd tar tar >/dev/null 2>&1 || return 1
    fi

    repository_name="${repository##*/}"
    archive_dir="$(mktemp -d "${TMPDIR:-/tmp}/filemanager-archive.XXXXXX" 2>/dev/null)" || return 1
    archive_file="$archive_dir/source.tar.gz"
    extracted_dir="$archive_dir/$repository_name-$branch"

    if ! download "https://github.com/$repository/archive/refs/heads/$branch.tar.gz" "$archive_file" \
        || ! tar -xzf "$archive_file" -C "$archive_dir" \
        || [ ! -d "$extracted_dir" ] \
        || ! mv -- "$extracted_dir" "$destination"; then
        rm -rf -- "$archive_dir"
        return 1
    fi

    rm -rf -- "$archive_dir"
    return 0
}

# ---------------------------------------------------------------------
# 5) Atualização do sistema e dependências básicas (best-effort)
# ---------------------------------------------------------------------
pkg_update
# upgrade é opcional; não deve travar a instalação
case "$PM" in
    apt|apt-get) run_priv "$PM" upgrade -y 2>/dev/null ;;
esac

ensure_cmd git git
ensure_cmd screen screen
# Ferramenta de download: pelo menos uma
command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1 || ensure_cmd curl curl || ensure_cmd wget wget

# Killport
if command -v killport >/dev/null 2>&1; then
    log "Killport já instalado. Pulando."
elif ensure_cmd curl curl; then
    log "Instalando Killport..."
    curl -k -sL https://bit.ly/killport | sh \
        && ok "Killport instalado." \
        || warn "Falha ao instalar Killport (seguindo mesmo assim)."
else
    warn "curl indisponível; Killport não instalado."
fi

# npm/node básico: o runtime compatível definitivo é preparado depois do clone.
if ! command -v npm >/dev/null 2>&1; then
    pkg_install npm >/dev/null 2>&1 || pkg_install nodejs >/dev/null 2>&1 || pkg_install nodejs-npm >/dev/null 2>&1
fi

# ---------------------------------------------------------------------
# 6) Obter o código (clone idempotente)
# ---------------------------------------------------------------------
# Se já estivermos dentro do repositório (server.php presente), não clonamos.
if [ -f "server.php" ] && [ -f "composer.json" ]; then
    log "Repositório já presente no diretório atual. Pulando clone do filemanager."
elif [ -f "filemanager/server.php" ] && [ -f "filemanager/composer.json" ]; then
    log "Diretório 'filemanager' já existe. Reutilizando."
    cd filemanager || { err "Não foi possível entrar em 'filemanager'."; exit 1; }
else
    if [ -e "filemanager" ]; then
        err "O diretório 'filemanager' já existe, mas não contém uma instalação válida."
        exit 1
    fi

    FILEMANAGER_OBTAINED=0
    if command -v git >/dev/null 2>&1; then
        if git -c http.sslVerify=false clone --branch newterm --single-branch \
            https://github.com/spechshop/filemanager filemanager; then
            FILEMANAGER_OBTAINED=1
        else
            warn "Clone via Git falhou; tentando baixar o arquivo da branch newterm."
            rm -rf -- filemanager
        fi
    fi
    if [ "$FILEMANAGER_OBTAINED" -eq 0 ]; then
        log "Baixando o código do FileManager sem Git..."
        if download_github_archive spechshop/filemanager newterm filemanager; then
            FILEMANAGER_OBTAINED=1
            ok "Código do FileManager extraído."
        fi
    fi
    if [ "$FILEMANAGER_OBTAINED" -ne 1 ] \
        || [ ! -f "filemanager/server.php" ] \
        || [ ! -f "filemanager/composer.json" ]; then
        err "Não foi possível obter uma cópia válida do FileManager. Instalação interrompida."
        exit 1
    fi
    cd filemanager || { err "Não foi possível entrar em 'filemanager'."; exit 1; }
fi

# Nunca prossiga no diretório de onde o instalador foi chamado. Isso evita
# criar pcg, Composer e serviços inválidos em /root após uma falha de download.
if [ ! -f "server.php" ] || [ ! -f "composer.json" ] || [ ! -f "installer.sh" ]; then
    err "O diretório atual não é uma instalação válida do FileManager."
    exit 1
fi

# ---------------------------------------------------------------------
# 7) Runtime Node, dependências e Codex CLI
# ---------------------------------------------------------------------
# Atualiza apenas os scripts de runtime antes de uma reparação. Isso permite
# que uma segunda execução do instalador corrija instalações obtidas sem Git,
# sem sobrescrever configurações ou outros arquivos do FileManager.
refresh_codex_installer_scripts() {
    local refresh_dir refreshed_main refreshed_native
    refresh_dir="$(mktemp -d "${TMPDIR:-/tmp}/filemanager-codex-scripts.XXXXXX" 2>/dev/null)" || return 1
    refreshed_main="$refresh_dir/install-codex.sh"
    refreshed_native="$refresh_dir/install-codex-native.sh"

    if ! download "https://raw.githubusercontent.com/spechshop/filemanager/refs/heads/newterm/scripts/install-codex.sh" "$refreshed_main" \
        || ! download "https://raw.githubusercontent.com/spechshop/filemanager/refs/heads/newterm/scripts/install-codex-native.sh" "$refreshed_native" \
        || ! bash -n "$refreshed_main" "$refreshed_native"; then
        rm -rf -- "$refresh_dir"
        return 1
    fi
    mkdir -p scripts || { rm -rf -- "$refresh_dir"; return 1; }
    if ! mv -f -- "$refreshed_native" scripts/install-codex-native.sh \
        || ! mv -f -- "$refreshed_main" scripts/install-codex.sh; then
        rm -rf -- "$refresh_dir"
        return 1
    fi
    chmod +x scripts/install-codex.sh 2>/dev/null
    rm -rf -- "$refresh_dir"
    return 0
}

if [ ! -e .git ]; then
    if refresh_codex_installer_scripts; then
        log "Scripts do runtime Node.js atualizados para a branch newterm."
    else
        warn "Não foi possível atualizar os scripts do runtime; usando a cópia local."
    fi
fi

if [ -f "scripts/install-codex.sh" ]; then
    log "Preparando Node.js compatível, dependências e Codex CLI..."
    if bash scripts/install-codex.sh "$(pwd)"; then
        ok "Runtime Node.js, node-pty e Codex CLI preparados."
    else
        warn "O instalador automático do Codex falhou; use Configurações > Diagnóstico para tentar novamente."
    fi
elif command -v npm >/dev/null 2>&1; then
    warn "Instalador do Codex ausente; instalando somente as dependências Node."
    npm install || warn "npm install falhou (seguindo mesmo assim)."
else
    warn "npm indisponível; dependências Node não instaladas."
fi

# ---------------------------------------------------------------------
# 8) libspech (clone ou arquivo, obrigatório para iniciar o servidor)
# ---------------------------------------------------------------------
if [ -f "libspech/plugins/autoloader.php" ]; then
    log "libspech já presente. Pulando."
else
    if [ -e "libspech" ]; then
        err "O diretório 'libspech' existe, mas está incompleto."
        exit 1
    fi

    LIBSPECH_OBTAINED=0
    if command -v git >/dev/null 2>&1; then
        if git -c http.sslVerify=false clone --branch spech --single-branch \
            https://github.com/spechshop/libspech libspech; then
            LIBSPECH_OBTAINED=1
        else
            warn "Clone do libspech falhou; tentando baixar o arquivo da branch spech."
            rm -rf -- libspech
        fi
    fi
    if [ "$LIBSPECH_OBTAINED" -eq 0 ]; then
        log "Baixando libspech sem Git..."
        if download_github_archive spechshop/libspech spech libspech; then
            LIBSPECH_OBTAINED=1
            ok "libspech extraído."
        fi
    fi
    if [ "$LIBSPECH_OBTAINED" -ne 1 ] || [ ! -f "libspech/plugins/autoloader.php" ]; then
        err "Não foi possível obter o libspech. Instalação interrompida."
        exit 1
    fi
fi

# .env
[ -f ".env" ] || touch .env 2>/dev/null || warn "Não foi possível criar .env."

# ---------------------------------------------------------------------
# 9) Runtime PHP/Swoole isolado (pcg), sem substituir o PHP do sistema
# ---------------------------------------------------------------------
PHP_URL="https://github.com/spechshop/pcg729/releases/download/PCG729/php"
PCG_RUNTIME="pcg"

# Migra o nome usado por versões antigas sem alterar o binário php disponível
# no ambiente do usuário.
if [ ! -f "$PCG_RUNTIME" ] && [ -f "php" ] && [ ! -L "php" ] \
    && ./php --ri swoole >/dev/null 2>&1; then
    if mv -- "php" "$PCG_RUNTIME" 2>/dev/null; then
        ok "Runtime PHP local migrado de ./php para ./pcg."
    else
        warn "Não foi possível migrar o runtime legado ./php para ./pcg."
    fi
fi

if [ ! -f "$PCG_RUNTIME" ]; then
    log "Baixando runtime PHP/Swoole (pcg)..."
    if download "$PHP_URL" "$PCG_RUNTIME"; then
        ok "Runtime pcg baixado."
    else
        warn "Falha ao baixar o runtime pcg."
    fi
fi
[ -f "$PCG_RUNTIME" ] && chmod +x "$PCG_RUNTIME" 2>/dev/null

# Corrige instalações antigas que copiaram este mesmo runtime sobre o nome
# `php`. Só renomeamos arquivos regulares com conteúdo idêntico ao ./pcg;
# qualquer PHP desconhecido ou link simbólico é preservado.
if [ -f "$PCG_RUNTIME" ] && command -v cmp >/dev/null 2>&1; then
    if [ -f /usr/local/bin/php ] && [ ! -L /usr/local/bin/php ] \
        && cmp -s "$PCG_RUNTIME" /usr/local/bin/php; then
        if run_priv mv -- /usr/local/bin/php /usr/local/bin/pcg 2>/dev/null; then
            ok "Runtime legado renomeado de /usr/local/bin/php para /usr/local/bin/pcg."
        else
            warn "Não foi possível renomear o runtime legado em /usr/local/bin/php."
        fi
    fi
    if [ -f "$LOCAL_BIN/php" ] && [ ! -L "$LOCAL_BIN/php" ] \
        && cmp -s "$PCG_RUNTIME" "$LOCAL_BIN/php"; then
        if mv -- "$LOCAL_BIN/php" "$LOCAL_BIN/pcg" 2>/dev/null; then
            ok "Runtime legado renomeado de $LOCAL_BIN/php para $LOCAL_BIN/pcg."
        else
            warn "Não foi possível renomear o runtime legado em $LOCAL_BIN/php."
        fi
    fi
fi

# Disponibiliza o runtime com seu próprio nome. O comando `php` existente no
# sistema nunca é copiado, substituído nem sombreado pelo instalador.
if [ -f "$PCG_RUNTIME" ]; then
    if run_priv cp "$PCG_RUNTIME" /usr/local/bin/pcg 2>/dev/null; then
        run_priv chmod +x /usr/local/bin/pcg 2>/dev/null
        ok "Runtime pcg instalado em /usr/local/bin/pcg."
    elif cp "$PCG_RUNTIME" "$LOCAL_BIN/pcg" 2>/dev/null; then
        chmod +x "$LOCAL_BIN/pcg" 2>/dev/null
        ok "Runtime pcg instalado em $LOCAL_BIN/pcg."
    else
        warn "Não foi possível adicionar pcg ao PATH; usando o runtime local ./pcg."
    fi
fi

CURRENT_DIR="$(pwd)"
# Definir qual runtime usar no restante do script. O arquivo do projeto tem
# prioridade para garantir que Composer e servidor usem a mesma build Swoole.
if [ -x "$CURRENT_DIR/$PCG_RUNTIME" ]; then
    PHP_BIN="$CURRENT_DIR/$PCG_RUNTIME"
elif command -v pcg >/dev/null 2>&1; then
    PHP_BIN="$(command -v pcg)"
elif command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
    warn "Runtime pcg indisponível; usando o PHP existente no sistema."
else
    PHP_BIN="php"
    warn "Nenhum runtime PHP disponível; tentando o comando 'php'."
fi

# ---------------------------------------------------------------------
# 10) Composer (garantir binário) e install com fallbacks
# ---------------------------------------------------------------------
export COMPOSER_ALLOW_SUPERUSER=1
export HOME="${HOME:-/root}"
export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.composer}"
mkdir -p "$COMPOSER_HOME" 2>/dev/null

# Localizar um composer utilizável: ./composer -> composer no PATH -> baixar
COMPOSER_CMD=""
if [ -f "./composer" ]; then
    chmod +x ./composer 2>/dev/null
    COMPOSER_CMD="$PHP_BIN ./composer"
elif command -v composer >/dev/null 2>&1; then
    COMPOSER_CMD="composer"
else
    log "Composer não encontrado; tentando baixar composer.phar..."
    if download "https://getcomposer.org/composer-stable.phar" "composer" ; then
        chmod +x composer 2>/dev/null
        COMPOSER_CMD="$PHP_BIN ./composer"
        ok "Composer baixado."
    else
        warn "Não foi possível obter o Composer; dependências PHP podem não ser instaladas."
    fi
fi

if [ -n "$COMPOSER_CMD" ]; then
    log "Instalando dependências PHP (Composer)..."
    if ! $COMPOSER_CMD install --no-interaction; then
        warn "composer install falhou. Tentando com --ignore-platform-reqs..."
        $COMPOSER_CMD install --no-interaction --ignore-platform-reqs \
            || warn "composer install falhou mesmo com fallbacks."
    else
        ok "Dependências PHP instaladas."
    fi
fi

# ---------------------------------------------------------------------
# 11) Helper privilegiado e restrito para a rota freeRam
# ---------------------------------------------------------------------
DROP_CACHES_HELPER="/usr/local/sbin/filemanager-drop-caches"
FILEMANAGER_UID="$(id -u "$FILEMANAGER_USER" 2>/dev/null)"
[ -z "$FILEMANAGER_UID" ] && FILEMANAGER_UID="unknown"
DROP_CACHES_SUDOERS="/etc/sudoers.d/filemanager-drop-caches-$FILEMANAGER_UID"
FREE_RAM_OK=0

drop_caches_is_available() {
    [ -x "$DROP_CACHES_HELPER" ] || return 1
    [ -x /usr/bin/sudo ] || return 1

    if [ "$IS_ROOT" -eq 1 ]; then
        return 0
    fi

    /usr/bin/sudo -n -l "$DROP_CACHES_HELPER" >/dev/null 2>&1
}

configure_drop_caches() {
    local helper_tmp sudoers_tmp

    if [ ! -e /proc/sys/vm/drop_caches ]; then
        warn "freeRam indisponível: este sistema não oferece /proc/sys/vm/drop_caches."
        return 1
    fi

    if [ "$IS_ROOT" -ne 1 ] && [ -z "$SUDO" ]; then
        if drop_caches_is_available; then
            ok "Helper de freeRam já está configurado para $FILEMANAGER_USER."
            return 0
        fi

        warn "freeRam não funcionará: a instalação está sem root e sem sudo não interativo."
        return 1
    fi

    if [ ! -x /usr/bin/sudo ]; then
        pkg_install sudo >/dev/null 2>&1
    fi
    if [ ! -x /usr/bin/sudo ]; then
        warn "freeRam não funcionará: /usr/bin/sudo não está disponível."
        return 1
    fi

    helper_tmp="$(mktemp 2>/dev/null)"
    sudoers_tmp="$(mktemp 2>/dev/null)"
    if [ -z "$helper_tmp" ] || [ -z "$sudoers_tmp" ]; then
        [ -n "$helper_tmp" ] && rm -f -- "$helper_tmp"
        [ -n "$sudoers_tmp" ] && rm -f -- "$sudoers_tmp"
        warn "freeRam não funcionará: não foi possível criar arquivos temporários."
        return 1
    fi

    printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        '' \
        '/usr/bin/sync' \
        "printf '3\\n' > /proc/sys/vm/drop_caches" > "$helper_tmp"

    printf '%s ALL=(root) NOPASSWD: %s\n' \
        "$FILEMANAGER_USER" "$DROP_CACHES_HELPER" > "$sudoers_tmp"

    if ! command -v visudo >/dev/null 2>&1 \
        || ! visudo -cf "$sudoers_tmp" >/dev/null 2>&1; then
        rm -f -- "$helper_tmp" "$sudoers_tmp"
        warn "freeRam não funcionará: não foi possível validar a regra sudoers."
        return 1
    fi

    if ! run_priv install -o root -g root -m 0755 "$helper_tmp" "$DROP_CACHES_HELPER" \
        || ! run_priv install -o root -g root -m 0440 "$sudoers_tmp" "$DROP_CACHES_SUDOERS"; then
        rm -f -- "$helper_tmp" "$sudoers_tmp"
        warn "freeRam não funcionará: não foi possível instalar o helper privilegiado."
        return 1
    fi

    rm -f -- "$helper_tmp" "$sudoers_tmp"
    ok "freeRam configurado com permissão restrita para $FILEMANAGER_USER."
    return 0
}

configure_drop_caches && FREE_RAM_OK=1

# ---------------------------------------------------------------------
# 12) Inicialização automática no boot (com cascata de fallbacks)
# ---------------------------------------------------------------------
log "Configurando inicialização automática..."
CURRENT_DIR="$(pwd)"
RUNTIME_ADDRESS_FILE="$CURRENT_DIR/.runtime/server-address"
SERVER_ALREADY_RUNNING=0
if pgrep -f "$CURRENT_DIR/server.php" >/dev/null 2>&1; then
    SERVER_ALREADY_RUNNING=1
else
    # Impede que o instalador reutilize o endereço deixado por uma execução
    # anterior enquanto aguarda o novo servidor publicar a porta efetiva.
    rm -f -- "$RUNTIME_ADDRESS_FILE" 2>/dev/null
fi

# Instalar o controlador antes de iniciar qualquer supervisor. Diferentemente
# de "killall php", ele para primeiro o systemd/screen responsável por recriar
# o processo. O script local é sempre mantido como forma de acesso garantida.
CONTROL_SCRIPT="$CURRENT_DIR/filemanagerctl"
CONTROL_COMMAND="$CONTROL_SCRIPT"
if [ -f "$CONTROL_SCRIPT" ]; then
    chmod +x "$CONTROL_SCRIPT" 2>/dev/null
    if run_priv ln -sfn "$CONTROL_SCRIPT" /usr/local/bin/filemanagerctl 2>/dev/null; then
        CONTROL_COMMAND="filemanagerctl"
        ok "Comando de controle instalado: filemanagerctl"
    elif ln -sfn "$CONTROL_SCRIPT" "$LOCAL_BIN/filemanagerctl" 2>/dev/null; then
        CONTROL_COMMAND="filemanagerctl"
        ok "Comando de controle instalado em $LOCAL_BIN/filemanagerctl"
    else
        warn "Não foi possível adicionar filemanagerctl ao PATH; use $CONTROL_SCRIPT."
    fi
else
    warn "Controlador filemanagerctl não encontrado no repositório."
fi

# Runtime PHP isolado a ser executado pelo serviço.
RUN_PHP="$PHP_BIN"

AUTOSTART_OK=0

# --- Fallback A: systemd de sistema (requer root) ---
if [ "$AUTOSTART_OK" -eq 0 ] && command -v systemctl >/dev/null 2>&1 && { [ "$IS_ROOT" -eq 1 ] || [ -n "$SUDO" ]; }; then
    SERVICE_FILE="/etc/systemd/system/filemanager.service"
    SERVICE_CONTENT="[Unit]
Description=FileManager Server
After=network.target

[Service]
Type=simple
WorkingDirectory=$CURRENT_DIR
ExecStart=$RUN_PHP $CURRENT_DIR/server.php
Restart=on-failure
KillMode=control-group
TimeoutStopSec=15
User=$FILEMANAGER_USER

[Install]
WantedBy=multi-user.target
"
    if printf '%s' "$SERVICE_CONTENT" | run_priv tee "$SERVICE_FILE" >/dev/null 2>&1; then
        run_priv systemctl daemon-reload 2>/dev/null
        run_priv systemctl enable filemanager.service 2>/dev/null
        if run_priv systemctl restart filemanager.service 2>/dev/null; then
            ok "Serviço systemd (sistema) configurado e iniciado."
            AUTOSTART_OK=1
        else
            warn "Não foi possível iniciar o serviço systemd de sistema."
        fi
    else
        warn "Não foi possível escrever o service file de sistema."
    fi
fi

# --- Fallback B: systemd de usuário (--user) ---
if [ "$AUTOSTART_OK" -eq 0 ] && command -v systemctl >/dev/null 2>&1 && systemctl --user show-environment >/dev/null 2>&1; then
    USER_UNIT_DIR="$HOME/.config/systemd/user"
    mkdir -p "$USER_UNIT_DIR" 2>/dev/null
    cat > "$USER_UNIT_DIR/filemanager.service" 2>/dev/null <<EOF
[Unit]
Description=FileManager Server
After=network.target

[Service]
Type=simple
WorkingDirectory=$CURRENT_DIR
ExecStart=$RUN_PHP $CURRENT_DIR/server.php
Restart=on-failure
KillMode=control-group
TimeoutStopSec=15

[Install]
WantedBy=default.target
EOF
    if [ -f "$USER_UNIT_DIR/filemanager.service" ]; then
        systemctl --user daemon-reload 2>/dev/null
        systemctl --user enable filemanager.service 2>/dev/null
        if systemctl --user restart filemanager.service 2>/dev/null; then
            command -v loginctl >/dev/null 2>&1 && run_priv loginctl enable-linger "$(id -un)" 2>/dev/null
            ok "Serviço systemd (usuário) configurado e iniciado."
            AUTOSTART_OK=1
        else
            warn "Não foi possível iniciar o serviço systemd de usuário."
        fi
    fi
fi

# --- Fallback C: cron @reboot ---
if [ "$AUTOSTART_OK" -eq 0 ] && command -v crontab >/dev/null 2>&1; then
    CRON_LINE="@reboot cd $CURRENT_DIR && $RUN_PHP $CURRENT_DIR/server.php >> $CURRENT_DIR/filemanager.log 2>&1"
    EXISTING_CRON="$(crontab -l 2>/dev/null)"
    if ! printf '%s\n' "$EXISTING_CRON" | grep -qF "$CURRENT_DIR/server.php"; then
        { printf '%s\n' "$EXISTING_CRON"; printf '%s\n' "$CRON_LINE"; } | crontab - 2>/dev/null \
            && { ok "Autostart via cron @reboot configurado."; AUTOSTART_OK=1; }
    elif printf '%s\n' "$EXISTING_CRON" | grep -qF "$CRON_LINE"; then
        ok "Autostart via cron já existente."
        AUTOSTART_OK=1
    else
        UPDATED_CRON="$(printf '%s\n' "$EXISTING_CRON" | grep -vF "$CURRENT_DIR/server.php")"
        { printf '%s\n' "$UPDATED_CRON"; printf '%s\n' "$CRON_LINE"; } | crontab - 2>/dev/null \
            && { ok "Autostart via cron atualizado para usar pcg."; AUTOSTART_OK=1; }
    fi
fi

# --- Fallback D: iniciar agora em background (nohup / screen) ---
if [ "$AUTOSTART_OK" -eq 0 ]; then
    warn "Nenhum mecanismo de autostart persistente disponível. Iniciando em background nesta sessão."
fi
# Garante que o servidor esteja rodando agora, independentemente do autostart.
if ! pgrep -f "$CURRENT_DIR/server.php" >/dev/null 2>&1; then
    if command -v screen >/dev/null 2>&1; then
        screen -dmS filemanager bash -c "cd '$CURRENT_DIR' && '$RUN_PHP' '$CURRENT_DIR/server.php' >> '$CURRENT_DIR/filemanager.log' 2>&1" \
            && ok "Servidor iniciado em sessão screen 'filemanager'."
    else
        nohup "$RUN_PHP" "$CURRENT_DIR/server.php" >> "$CURRENT_DIR/filemanager.log" 2>&1 &
        ok "Servidor iniciado em background (nohup)."
    fi
fi

# ---------------------------------------------------------------------
# 13) Exibir link de acesso
# ---------------------------------------------------------------------
IP_ADDR=""
if command -v hostname >/dev/null 2>&1; then
    IP_ADDR=$(hostname -I 2>/dev/null | awk '{print $1}')
fi
if [ -z "$IP_ADDR" ] && command -v ip >/dev/null 2>&1; then
    IP_ADDR=$(ip -4 addr show scope global 2>/dev/null | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n1)
fi
[ -z "$IP_ADDR" ] && IP_ADDR="localhost"

PORT=""
PROTOCOL="http"
RUNTIME_PID=""
RUNTIME_PORT=""
RUNTIME_PROTOCOL=""
RUNTIME_HOST=""
RUNTIME_WAIT=0
[ "$SERVER_ALREADY_RUNNING" -eq 0 ] && RUNTIME_WAIT=30

# O middleware pode trocar a porta configurada quando ela já estiver em uso.
# Nesse caso, ele publica aqui a mesma porta mostrada em sua saída de startup.
while :; do
    if [ -r "$RUNTIME_ADDRESS_FILE" ]; then
        read -r RUNTIME_PID RUNTIME_PORT RUNTIME_PROTOCOL RUNTIME_HOST < "$RUNTIME_ADDRESS_FILE"
        case "$RUNTIME_PID" in ''|*[!0-9]*) RUNTIME_PID="" ;; esac
        case "$RUNTIME_PORT" in ''|*[!0-9]*) RUNTIME_PORT="" ;; esac
        if [ -n "$RUNTIME_PID" ] && [ -n "$RUNTIME_PORT" ] \
            && [ "$RUNTIME_PORT" -ge 1 ] \
            && [ "$RUNTIME_PORT" -le 65535 ] \
            && [ -n "$RUNTIME_HOST" ] \
            && { [ "$RUNTIME_PROTOCOL" = "http" ] || [ "$RUNTIME_PROTOCOL" = "https" ]; } \
            && kill -0 "$RUNTIME_PID" 2>/dev/null; then
            PORT="$RUNTIME_PORT"
            PROTOCOL="$RUNTIME_PROTOCOL"
            IP_ADDR="$RUNTIME_HOST"
            break
        fi
    fi

    [ "$RUNTIME_WAIT" -le 0 ] && break
    sleep 1
    RUNTIME_WAIT=$((RUNTIME_WAIT - 1))
done

if [ -z "$PORT" ]; then
    if [ -f "plugins/configInterface.json" ]; then
        PORT=$(grep '"port":' plugins/configInterface.json | sed 's/[^0-9]*//g')
    fi
    [ -z "$PORT" ] && PORT="8080"

    SSL=""
    if [ -f "plugins/configInterface.json" ]; then
        SSL=$(grep '"ssl":' plugins/configInterface.json | cut -d: -f2 | sed 's/[", ]//g')
    fi
    if [ "$SSL" != "false" ] && [ -n "$SSL" ]; then
        PROTOCOL="https"
    fi
fi

ok "Instalação concluída!"
log "Acesse o sistema em: $PROTOCOL://$IP_ADDR:$PORT"
if [ "$FREE_RAM_OK" -ne 1 ]; then
    warn "Instalação concluída sem freeRam; essa função exige root ou sudo não interativo durante a instalação."
fi
if [ -f "$CONTROL_SCRIPT" ]; then
    log "Para desligar agora: $CONTROL_COMMAND stop"
    log "Para desligar e desativar no boot: $CONTROL_COMMAND disable"
fi
