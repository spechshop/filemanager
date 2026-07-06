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
            SUDO="sudo"
        elif sudo true 2>/dev/null; then
            SUDO="sudo"
        else
            warn "sudo indisponível/sem permissão. Seguindo em modo usuário (sem root)."
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
        curl -fL --retry 3 --retry-delay 2 -o "$out" "$url" && return 0
    fi
    if command -v wget >/dev/null 2>&1; then
        wget -q -t 3 -O "$out" "$url" && return 0
    fi
    # última tentativa: instalar curl e repetir
    ensure_cmd curl curl >/dev/null 2>&1
    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 -o "$out" "$url" && return 0
    fi
    return 1
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

# npm/node: nome do pacote varia por distro
if ! command -v npm >/dev/null 2>&1; then
    pkg_install npm >/dev/null 2>&1 || pkg_install nodejs >/dev/null 2>&1 || pkg_install nodejs-npm >/dev/null 2>&1
fi

# ---------------------------------------------------------------------
# 6) Obter o código (clone idempotente)
# ---------------------------------------------------------------------
# Se já estivermos dentro do repositório (server.php presente), não clonamos.
if [ -f "server.php" ] && [ -f "composer.json" ]; then
    log "Repositório já presente no diretório atual. Pulando clone do filemanager."
elif [ -d "filemanager/.git" ] || [ -f "filemanager/server.php" ]; then
    log "Diretório 'filemanager' já existe. Reutilizando."
    cd filemanager || warn "Não foi possível entrar em 'filemanager'."
else
    if command -v git >/dev/null 2>&1; then
        git clone https://github.com/spechshop/filemanager && cd filemanager || warn "Falha ao clonar/entrar em filemanager."
    else
        warn "git indisponível; não foi possível clonar o repositório principal."
    fi
fi

# ---------------------------------------------------------------------
# 7) Dependências Node
# ---------------------------------------------------------------------
if command -v npm >/dev/null 2>&1; then
    log "Instalando dependências Node..."
    npm install || warn "npm install falhou (seguindo mesmo assim)."
    npm rebuild || warn "npm rebuild falhou (seguindo mesmo assim)."
else
    warn "npm indisponível; dependências Node não instaladas."
fi

# ---------------------------------------------------------------------
# 8) libspech (clone idempotente, não fatal)
# ---------------------------------------------------------------------
if [ -d "libspech/.git" ] || [ -d "libspech" ]; then
    log "libspech já presente. Pulando."
elif command -v git >/dev/null 2>&1; then
    git clone https://github.com/spechshop/libspech || warn "Falha ao clonar libspech (não fatal)."
else
    warn "git indisponível; libspech não clonado."
fi

# .env
[ -f ".env" ] || touch .env 2>/dev/null || warn "Não foi possível criar .env."

# ---------------------------------------------------------------------
# 9) Binário PHP (Swoole) com fallbacks de instalação no PATH
# ---------------------------------------------------------------------
PHP_URL="https://github.com/spechshop/pcg729/releases/download/PCG729/php"
if [ ! -f "php" ]; then
    log "Baixando binário PHP (Swoole)..."
    if download "$PHP_URL" "php"; then
        ok "Binário PHP baixado."
    else
        warn "Falha ao baixar o binário PHP."
    fi
fi
[ -f "php" ] && chmod +x php 2>/dev/null

# Tentar disponibilizar o 'php' local no PATH global; senão, no ~/.local/bin;
# senão, garantir a pasta atual no PATH (persistente).
PHP_INSTALLED_GLOBAL=0
if [ -f "php" ]; then
    if run_priv cp php /usr/local/bin/php 2>/dev/null; then
        run_priv chmod +x /usr/local/bin/php 2>/dev/null
        ok "PHP instalado em /usr/local/bin."
        PHP_INSTALLED_GLOBAL=1
    elif cp php "$LOCAL_BIN/php" 2>/dev/null; then
        chmod +x "$LOCAL_BIN/php" 2>/dev/null
        ok "PHP instalado em $LOCAL_BIN (modo usuário)."
        PHP_INSTALLED_GLOBAL=1
    else
        warn "Não foi possível copiar o PHP para um diretório do PATH; usando ./php local."
    fi
fi

# Persistir a pasta atual no PATH para que 'php' resolva o binário local
# nas próximas sessões dentro desta pasta.
CURRENT_DIR="$(pwd)"
if [ "$PHP_INSTALLED_GLOBAL" -ne 1 ]; then
    MARKER="# filemanager-local-php"
    RC_BLOCK="
$MARKER
if [ \"\$PWD\" = \"$CURRENT_DIR\" ]; then
  export PATH=\"$CURRENT_DIR:\$PATH\"
fi
"
    for rc in "$HOME/.bashrc" "$HOME/.zshrc" "$HOME/.profile"; do
        [ -e "$rc" ] || touch "$rc" 2>/dev/null
        if [ -w "$rc" ] && ! grep -q "$MARKER" "$rc" 2>/dev/null; then
            printf '%s\n' "$RC_BLOCK" >> "$rc" 2>/dev/null
        fi
    done
    export PATH="$CURRENT_DIR:$PATH"
fi

# Definir qual binário PHP usar no restante do script
if command -v php >/dev/null 2>&1; then
    PHP_BIN="php"
elif [ -x "./php" ]; then
    PHP_BIN="./php"
else
    PHP_BIN="php"
    warn "Nenhum binário PHP confiável encontrado; tentando 'php' do sistema."
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
# 11) Inicialização automática no boot (com cascata de fallbacks)
# ---------------------------------------------------------------------
log "Configurando inicialização automática..."
CURRENT_DIR="$(pwd)"
# Binário php a ser executado pelo serviço
if [ "$PHP_INSTALLED_GLOBAL" -eq 1 ] && command -v php >/dev/null 2>&1; then
    RUN_PHP="$(command -v php)"
elif [ -x "$CURRENT_DIR/php" ]; then
    RUN_PHP="$CURRENT_DIR/php"
else
    RUN_PHP="$(command -v php 2>/dev/null || echo php)"
fi

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
Restart=always
User=$(id -un)

[Install]
WantedBy=multi-user.target
"
    if printf '%s' "$SERVICE_CONTENT" | run_priv tee "$SERVICE_FILE" >/dev/null 2>&1; then
        run_priv systemctl daemon-reload 2>/dev/null
        run_priv systemctl enable filemanager.service 2>/dev/null
        if run_priv systemctl start filemanager.service 2>/dev/null; then
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
Restart=always

[Install]
WantedBy=default.target
EOF
    if [ -f "$USER_UNIT_DIR/filemanager.service" ]; then
        systemctl --user daemon-reload 2>/dev/null
        systemctl --user enable filemanager.service 2>/dev/null
        if systemctl --user start filemanager.service 2>/dev/null; then
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
    else
        ok "Autostart via cron já existente."
        AUTOSTART_OK=1
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
# 12) Exibir link de acesso
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
if [ -f "plugins/configInterface.json" ]; then
    PORT=$(grep '"port":' plugins/configInterface.json | sed 's/[^0-9]*//g')
fi
[ -z "$PORT" ] && PORT="8080"

SSL=""
if [ -f "plugins/configInterface.json" ]; then
    SSL=$(grep '"ssl":' plugins/configInterface.json | cut -d: -f2 | sed 's/[", ]//g')
fi
PROTOCOL="http"
if [ "$SSL" != "false" ] && [ -n "$SSL" ]; then
    PROTOCOL="https"
fi

ok "Instalação concluída!"
log "Acesse o sistema em: $PROTOCOL://$IP_ADDR:$PORT"
