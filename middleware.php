<?php

use libspech\Cache\cache as cacheLibSpech;
use plugins\Start\cache;
use Swoole\WebSocket\Server;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
global $server;
global $coroutinesProcess;
ini_set('memory_limit', '2000M');
ini_set('max_input_vars', '100000');

include 'libspech/plugins/autoloader.php';
sleep(1);
include 'plugins/autoload.php';


$serverSettings = cacheLibSpech::get('interface');
$interfacetr = cacheLibSpech::get('interface');

if (cacheLibSpech::get('interface')['ssl']) {
    if (array_key_exists('ssl_cert_file', $serverSettings['serverSettings'])) {
        if (!file_exists(cacheLibSpech::get('interface')['serverSettings']['ssl_cert_file'])) {
            $keyFile = $interfacetr['serverSettings']['ssl_key_file'];
            $certFile = $interfacetr['serverSettings']['ssl_cert_file'];
            \libspech\Cli\cli::pcl("Generating SSL certificates...");
            \libspech\Cli\cli::pcl("Arquivos: $keyFile, $certFile");

            // Gerar chave privada e certificado em arquivos separados
            shell_exec('openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout ' . escapeshellarg($keyFile) . ' -out ' . escapeshellarg($certFile) . ' -subj "/C=BR/ST=State/L=City/O=Organization/OU=Unit/CN=localhost" 2>&1');
            sleep(4);
            // Aguardar a criação dos arquivos
            $maxWait = 10;
            $waited = 0;
            while ($waited < $maxWait) {
                if (file_exists($certFile) && file_exists($keyFile)) {
                    break;
                }
                sleep(1);
                $waited++;
            }


            if (!file_exists($certFile) || !file_exists($keyFile)) {
                throw new Error("Falha ao gerar certificados SSL. Verifique se o OpenSSL está instalado.");
            } else {
                $serverSettings= cacheLibSpech::get('interface')['serverSettings'];
                $serverSettings['ssl_cert_file'] = $certFile;
                $serverSettings['ssl_key_file'] = $keyFile;
            }
        }


    } else {
        throw new Error("INVALID SSL CONFIGURATION: ssl_cert_file and ssl_key_file must be set in interface.json");
    }
}

$host = cache::global()['interface']['host'];
$port = cache::global()['interface']['port'];
try {
    $server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
} catch (Throwable $e) {
    echo "❌ Erro ao iniciar o servidor: {$e->getMessage()}\n";
    sleep(1);
    exit(1);
}

if (!function_exists('portAlive')) {
    function portAlive(mixed $port): bool
    {
        $host = "0.0.0.0";
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        var_dump($fp);
        if (!$fp) {
            return false;
        }
        fclose($fp);
        return true;
    }
}
co\run(function () {
    if (!portAlive(6060)) {
        shell_exec("screen -dmS nodePTY node pty");
    } else {
        echo "Port 6060 is already in use.\n";
    }
    if (!portAlive(3090)) {
        //shell_exec("screen -dmS nodeGPT node gpt");
    } else {
        echo "Port 3090 is already in use.\n";
    }
});
if (cacheLibSpech::get('interface')['ssl']) {
      $serverSettings= cacheLibSpech::get('interface')['serverSettings'];
}


$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('Start', '\plugins\Start\server::start');
$server->on('Request', '\plugins\Request\server::request');
$server->start();