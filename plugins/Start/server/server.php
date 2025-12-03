<?php

namespace plugins\Start;

use FilesystemIterator;
use plugins\Utils\cache\observer;
use RecursiveDirectoryIterator;
use RecursiveTreeIterator;
use Swoole\Coroutine;
use Swoole\Table;
use Swoole\Timer;

class server
{
    public static function tick(\Swoole\Http\Server $server, int $milliseconds, Table $tableServer)
    {
        Timer::tick($milliseconds, function () use ($server, $tableServer) {
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
        Timer::tick(1000, function () {
            $dataKeys = \plugins\Database\call::data();
            $listRoutes = \plugins\Request\controller::listPages();                   
            foreach ($listRoutes as $listRoute) {
                $e = explode("/", $listRoute);
                $idKey = explode(".", $e[count($e) - 1])[0];
                $cachePages[$idKey] = \plugins\Utils\cache\bufferPages::get($idKey, __DIR__);
            }
        });
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $server->host, $server->port, PHP_EOL), "yellow");
        self::tick($server, 10000, $tableServer);
    }
}
