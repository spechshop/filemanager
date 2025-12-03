<?php

use plugins\Start\cache;
use Swoole\WebSocket\Server;
Swoole\Runtime::enableCoroutine();
global $server;
global $coroutinesProcess;
ini_set('memory_limit', '2000M');
ini_set('max_input_vars', '100000');
include 'plugins/autoload.php';
include 'vendor/autoload.php';
$serverSettings = cache::global()['interface']['serverSettings'];
if (cache::global()['interface']['ssl']) {
    //$serverSettings['ssl_cert_file'] = cache::global()['interface']['ssl'] . '/localhost.crt';
    //$serverSettings['ssl_key_file'] = cache::global()['interface']['ssl'] . '/localhost.key';
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
$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('Start', '\plugins\Start\server::start');
$server->on('Request', '\plugins\Request\server::request');
$server->start();