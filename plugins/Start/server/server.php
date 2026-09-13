<?php

namespace plugins\Start;

use libspech\Cli\cli;
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

    private static ?int $watchTimer = null;
    private static ?int $restartTimer = null;

    public static function stop(): void
    {
        foreach ([self::$watchTimer, self::$restartTimer] as $timer) {
            if ($timer !== null) {
                Timer::clear($timer);
            }
        }
        self::$watchTimer = self::$restartTimer = null;
    }

    public static function tick(\Swoole\Http\Server $server, int $milliseconds): void
    {
        $root = dirname(__DIR__, 3);
        $watcher = new fileWatcher([$root . '/plugins', $root . '/libspech/plugins'], $GLOBALS['allowObservable'] ?? []);
        // Take the first snapshot now, not ten seconds after accepting traffic.
        $watcher->changes();
        self::$watchTimer = Timer::tick($milliseconds, static function () use ($server, $watcher): void {
            if (!self::autoRestartEnabled() || self::$restartTimer !== null) {
                return;
            }
            $changed = $watcher->changes();
            if ($changed === []) {
                return;
            }
            // A single debounce for a batch of editor saves, not a timer per file.
            self::$restartTimer = Timer::after(250, static function () use ($server, $changed): void {
                self::$restartTimer = null;
                self::stop();
                cli::pcl('Observed files changed: ' . implode(', ', $changed));
                $server->shutdown();
            });
        });
    }

    public static function start(\Swoole\Http\Server $server): void
    {
        $cli = new \plugins\Start\console();
        $prefix = "http://";
        if ($server->port === 443) {
            $prefix = "https://";
        }
        if (!empty($server->setting["ssl_cert_file"])) {
            $prefix = "https://";
        }
        $localIp = (string) \libspech\Network\network::getLocalIp();
        self::publishRuntimeAddress($server, $prefix, $localIp);
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $server->host, $server->port, PHP_EOL), "yellow");
        print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $localIp, $server->port, PHP_EOL), "yellow");
        
        self::tick($server, 10000);
    }
}
