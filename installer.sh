apt install git --yes
apt install npm --yes
git clone https://github.com/spechshop/filemanager
# shellcheck disable=SC2164
cd filemanager
npm rebuild
npm install
git clone https://github.com/spechshop/libspech
wget https://github.com/spechshop/pcg729/releases/download/PCG729/php
chmod -x php
cp php /usr/local/bin
export COMPOSER_ALLOW_SUPERUSER=1
./composer install
php server.php