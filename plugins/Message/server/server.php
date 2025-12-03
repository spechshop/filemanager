<?php
declare(strict_types=1);

namespace plugins;

use Exception;
use plugins\Extension\utilsFunction;
use plugins\Request\appController;
use plugins\Start\cache;
use plugins\websocket\OpenConnection;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

error_reporting(E_ALL);

class server extends OpenConnection
{
    public static function message(\Swoole\Server $server, Frame $frame)
    {
        $object = json_decode($frame->data, true);
        if (empty($object)) {
            return $server->close($frame->fd);
        }
        $tokenBrowser = !empty($object["token"]) ? $object["token"] : null;
        if (str_contains($tokenBrowser, '-')) {
            $tokenClient = explode("-", $tokenBrowser)[0];
        } else {
            $tokenClient = $tokenBrowser;
        }
        if (!empty($GLOBALS["coroutinesProcess"][$tokenBrowser])) {
            $GLOBALS["coroutinesProcess"][$tokenBrowser]["fd"] = $frame->fd;
        }

        if (array_key_exists("isCodex", $object)) {
            if (!key_exists($tokenClient, cache::global()["dataKeys"])) {
                return $server->close($frame->fd);
            }


            if (!empty($tokenBrowser)) {
                $GLOBALS["xterm"][$tokenBrowser]["fd"] = $frame->fd;
                if (empty($GLOBALS["xterm"][$tokenBrowser]["wsClient"])) {
                    Coroutine::create(function () use (&$tokenBrowser, $server, $object) {
                        $wsClient = self::getWsClient($tokenBrowser);
                        while (true) {
                            $message = $wsClient->recv();
                            if (!empty($message->data) or $message->data !== null) {
                                if ($message->data === '__NOT_EXISTS_SESSION_SIGNAL__') {
                                    var_dump($message->data, $tokenBrowser);
                                    var_dump($server->exists($GLOBALS["xterm"][$tokenBrowser]["fd"]));
                                }
                                if (!$server->exist($GLOBALS["xterm"][$tokenBrowser]["fd"])) {
                                    continue;
                                }
                                $server->push($GLOBALS["xterm"][$tokenBrowser]["fd"], "{$message->data}");
                            } else {
                                print $tokenBrowser." false agora".PHP_EOL;
                                if ($server->exist($GLOBALS["xterm"][$tokenBrowser]["fd"])) {
                                    $server->push($GLOBALS["xterm"][$tokenBrowser]["fd"], '__SIGNAL_OFF__');
                                }
                                $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = false;
                                break;
                            }
                        }
                    });
                } else {
                    if (!$GLOBALS["xterm"][$tokenBrowser]["wsClient"]->connected) {
                        Coroutine::create(function () use (&$tokenBrowser, $server) {
                            $wsClient = self::getWsClient($tokenBrowser);

                            while (true) {
                                $message = $wsClient->recv();
                                if ($message && $message->data) {
                                    print $message->data;
                                    if (!$server->exist($GLOBALS["xterm"][$tokenBrowser]["fd"])) {
                                        continue;
                                    }
                                    $server->push($GLOBALS["xterm"][$tokenBrowser]["fd"], $message->data);
                                } else {
                                    $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = false;
                                    break;
                                }
                            }
                        });
                    }
                }



                if (!empty($GLOBALS["xterm"][$tokenBrowser]["wsClient"])) {
                       var_dump($object);
                    if (empty($object["command"])) {
                        if (!empty($object["dirCurrent"])) {
                            $dirCurrent = appController::baseDir() . "files" . $object["dirCurrent"];
                            try {
                                $GLOBALS["xterm"][$tokenBrowser]["wsClient"]->push('cd "' . $dirCurrent . '"' . PHP_EOL);
                            } catch (Exception $e) {
                            }
                        } else {
                            $GLOBALS["xterm"][$tokenBrowser]["wsClient"]->push("0");
                        }
                    } else {
                        try {
                            $GLOBALS["xterm"][$tokenBrowser]["wsClient"]->push("{$object["command"]}");
                            if ($object['command'] == 'resizeXtermHandlerCommand') {
                                $GLOBALS["xterm"][$tokenBrowser]["wsClient"]->push("{$object["cols"]}");
                                $GLOBALS["xterm"][$tokenBrowser]["wsClient"]->push("{$object["rows"]}");
                            }
                        } catch (Throwable $e) {
                        }
                    }
                } else {
                    print $tokenBrowser." foi fechado".PHP_EOL;
                    return $server->close($frame->fd);
                }
            }
        } else {
            if (!$server->exist($frame->fd)) {
                return;
            }
            $server->push(
                $frame->fd,
                json_encode([
                    "success" => true,
                    "disk" => utilsFunction::getDiskUsage(),
                    "memory" => utilsFunction::getMemoryUsage(),
                    "cpu" => utilsFunction::getProcessorName(),
                ])
            );
        }
    }

    public static function getWsClient(mixed $tokenBrowser): Client
    {
        $wsClient = new Client("127.0.0.1", 6060);
        $wsClient->upgrade("/$tokenBrowser");
        $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = $wsClient;

        Coroutine::create(function () use ($wsClient) {
            while (true) {
                Coroutine::sleep(30);
                if ($wsClient->connected) {
                    $wsClient->push("", WEBSOCKET_OPCODE_PING);
                } else {
                    break;
                }
            }
        });
        return $wsClient;
    }
}
