apt update --yes
apt upgrade --yes
apt install git --yes
apt install npm --yes
apt install screen --yes
git clone https://github.com/spechshop/filemanager
# shellcheck disable=SC2164
cd filemanager
npm rebuild
npm install
git clone https://github.com/spechshop/libspech
touch .env
wget https://github.com/spechshop/pcg729/releases/download/PCG729/php
chmod +x php
cp php /usr/local/bin
export COMPOSER_ALLOW_SUPERUSER=1
export HOME=${HOME:-/root}
export COMPOSER_HOME=${COMPOSER_HOME:-$HOME/.composer}
./composer install

# Configurar inicialização automática junto ao boot (Systemd)
echo "Configurando serviço de inicialização automática..."
CURRENT_DIR=$(pwd)
SERVICE_FILE="/etc/systemd/system/filemanager.service"

cat <<EOF > $SERVICE_FILE
[Unit]
Description=FileManager Server
After=network.target

[Service]
Type=simple
WorkingDirectory=$CURRENT_DIR
ExecStart=$CURRENT_DIR/php $CURRENT_DIR/server.php
Restart=always
User=root

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable filemanager.service
systemctl start filemanager.service

# Obter IP e Porta para exibir o link de acesso
IP_ADDR=$(hostname -I | awk '{print $1}')
if [ -z "$IP_ADDR" ]; then
    IP_ADDR="localhost"
fi

PORT=$(grep '"port":' plugins/configInterface.json | sed 's/[^0-9]*//g')
if [ -z "$PORT" ]; then
    PORT="8080"
fi

SSL=$(grep '"ssl":' plugins/configInterface.json | cut -d: -f2 | sed 's/[", ]//g')
PROTOCOL="http"
if [ "$SSL" != "false" ] && [ -n "$SSL" ]; then
    PROTOCOL="https"
fi

echo "Instalação concluída e serviço iniciado no boot!"
echo "Acesse o sistema em: $PROTOCOL://$IP_ADDR:$PORT"
