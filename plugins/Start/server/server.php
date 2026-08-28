<?php

namespace plugins\Start;

use FilesystemIterator;
use libspech\Cli\cli;
use plugins\Utils\cache\observer;
use RecursiveDirectoryIterator;
use RecursiveTreeIterator;
use Swoole\Coroutine;
use Swoole\Table;
use Swoole\Timer;

class server
{
    private static function publishRuntimeAddress(
        \Swoole\Http\Server $server,
        string $protocol,
        string $host
    ): void
    {
        $runtimeDir = dirname(__DIR__, 3) . '/.runtime';
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
            error_log("[filemanager] Não foi possível criar o diretório de estado do servidor: {$runtimeDir}");
            return;
        }

        $addressFile = $runtimeDir . '/server-address';
        $temporaryFile = $addressFile . '.tmp.' . getmypid();
        $contents = sprintf(
            "%d %d %s %s\n",
            getmypid(),
            $server->port,
            rtrim($protocol, ':/'),
            trim($host)
        );

        if (@file_put_contents($temporaryFile, $contents, LOCK_EX) === false
            || !@rename($temporaryFile, $addressFile)) {
            @unlink($temporaryFile);
            error_log("[filemanager] Não foi possível registrar o endereço real do servidor em {$addressFile}");
        }
    }

    private static function autoRestartEnabled(): bool
    {
        $configPath = dirname(__DIR__, 2) . '/configInterface.json';
        $contents = @file_get_contents($configPath);
        $config = is_string($contents) ? json_decode($contents, true) : null;

        // Compatibilidade com instalações antigas e tolerância a uma escrita
        // inválida: o comportamento histórico era manter o autorestart ligado.
        return !is_array($config)
            || ($config['fileManager']['autoRestart'] ?? true) !== false;
    }

    public static function tick(\Swoole\Http\Server $server, int $milliseconds, Table $tableServer)
    {
        Timer::tick($milliseconds, function () use ($server, $tableServer) {

            if (!self::autoRestartEnabled()) {
                return;
            }





            $algorithm = "crc32";
            $Iterator = new RecursiveTreeIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS));
            foreach ($Iterator as $path) {
                $addressFile = explode("-./", $path)[1];
                $eTypeOf = explode(".", $addressFile);
                $typeOf = $eTypeOf[count($eTypeOf) - 1];
                if (in_array($typeOf, $GLOBALS["allowObservable"]) and strpos($path, "files/") === false) {
                    if (str_contains($addressFile, "files")) {
                        continue;
                    }
                    if (str_contains($addressFile, "vendor")) {
                        continue;
                    }
                    if (str_contains($addressFile, "node_modules")) {
                        continue;
                    }
                    if (str_contains($addressFile, "stubs")) {
                        continue;
                    }
                    if (str_contains($addressFile, "vendor")) {
                        continue;
                    }
                    if (str_contains($addressFile, "terminals")) {
                        continue;
                    }
                    if (!str_contains($addressFile, "plugins/")) {
                        continue;
                    }
                    
                    if (is_file($addressFile)) {
                        $id = md5($addressFile);
                        if (empty($tableServer->get($id, "identifier"))):
                            $tableServer->set($id, [
                                "identifier" => $id,
                                "data" => hash_file($algorithm, $addressFile),
                            ]);
                        endif;
                        $nowHash = hash_file($algorithm, $addressFile);
                        if ($nowHash !== $tableServer->get($id, "data")) {
                            $server->stop();
                            Timer::clearAll();
                            cli::pcl("File has been modified: " . $addressFile);
                            throw new \Exception();
                        }
                    }
                }
            }
        });
    }

    public static function start(\Swoole\Http\Server $server): void
    {
        $cli = new \plugins\Start\console();
        $tableServer = new \plugins\Start\tableServer();
        $prefix = "http://";
        if ($server->port === 443) {
            $prefix = "https://";
        }
        if (!empty($server->setting["ssl_cert_file"])) {
            $prefix = "https://";
        }
        $localIp = (string) \libspech\Network\network::getLocalIp();
        self::publishRuntimeAddress($server, $prefix, $localIp);
        Timer::tick(1000, function () {
            $dataKeys = \plugins\Database\call::data();
            $listRoutes = \plugins\Request\controller::listPages();                   
            foreach ($listRoutes as $listRoute) {
                $e = explode("/", $listRoute);
                $idKey = explode(".", $e[count($e) - 1])[0];
                $cachePages[$idKey] = \plugins\Utils\cache\bufferPages::get($idKey, __DIR__);
            }
            cache::global()['dataKeys'] = $dataKeys;
            cache::global()['listRoutes'] = $listRoutes;
            cache::global()['cachePages'] = $cachePages;
        });
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $server->host, $server->port, PHP_EOL), "yellow");
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $localIp, $server->port, PHP_EOL), "yellow");
        
        self::tick($server, 10000, $tableServer);
    }
}
