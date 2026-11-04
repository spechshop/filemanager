<?php

namespace plugins\Start;

use libspech\Cli\cli;
use Swoole\Timer;

class server
{
    private static bool $dualStack = false;

    public static function create(string $host, int $port): \Swoole\WebSocket\Server
    {
        $listenHost = $host;
        $ipv6Only = null;
        if (in_array($host, ['0.0.0.0', '::'], true)) {
            // Executado antes dos hooks: não mistura Socket nativo com sockets de coroutine.
            $probe = @socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
            if ($probe !== false) {
                if (@socket_bind($probe, '::', 0)) {
                    $ipv6Only = socket_get_option($probe, IPPROTO_IPV6, IPV6_V6ONLY);
                }
                socket_close($probe);
            }
            // Em Linux, :: normalmente já recebe IPv4; dois listeners conflitam.
            if ($host === '0.0.0.0' && $ipv6Only === 0) $listenHost = '::';
        }
        self::$dualStack = $listenHost === '::' && $ipv6Only === 0;
        $socketType = filter_var($listenHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP;
        $server = new \Swoole\WebSocket\Server($listenHost, $port, SWOOLE_PROCESS, $socketType | SWOOLE_SSL);
        if ($host === '0.0.0.0' && $ipv6Only === 1) {
            if ($server->addListener('::', $server->port, SWOOLE_SOCK_TCP6 | SWOOLE_SSL) === false) {
                throw new \RuntimeException('Não foi possível abrir o listener HTTP IPv6.');
            }
        }
        return $server;
    }

    private static function acceptsIpv4(\Swoole\Server\Port $listener): bool
    {
        return $listener->host === '0.0.0.0' || ($listener->host === '::' && self::$dualStack);
    }

    private static function listenAddresses(\Swoole\Http\Server $server): array
    {
        $addresses = [];
        foreach ($server->ports as $listener) {
            if ($listener->port !== $server->port) continue;
            $addresses[] = $listener->host;
            if (!in_array($listener->host, ['0.0.0.0', '::'], true)) continue;

            $ipv6 = $listener->host === '::';
            $addresses[] = $ipv6 ? '::1' : '127.0.0.1';
            $families = [$ipv6 ? AF_INET6 : AF_INET];
            if ($ipv6 && self::acceptsIpv4($listener)) {
                $addresses[] = '127.0.0.1';
                $families[] = AF_INET;
            }
            foreach ($families as $family) {
                foreach (@swoole_get_local_ip($family) ?: [] as $interface => $address) {
                    // Endereços link-local precisam da interface para serem acessíveis.
                    if ($family === AF_INET6 && str_starts_with(strtolower($address), 'fe80:') && !str_contains($address, '%')) {
                        $address .= '%' . $interface;
                    }
                    $addresses[] = $address;
                }
            }
        }
        return array_values(array_unique($addresses));
    }

    private static function publishRuntimeAddress(
        \Swoole\Http\Server $server,
        string $protocol,
        string $host
    ): void
    {
        $runtimeDir = getenv('FILEMANAGER_DATA_DIR') ?: dirname(__DIR__, 3) . '/.runtime';
        if (!is_dir($runtimeDir) && !@mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
            error_log("[filemanager] Não foi possível criar o diretório de estado do servidor: {$runtimeDir}");
            return;
        }

        $addressFile = $runtimeDir . '/server-address';
        $temporaryFile = tempnam($runtimeDir, '.server-address-');
        if ($temporaryFile === false) return;
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
        $configPath = getenv('FILEMANAGER_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/configInterface.json';
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
        $localIp = $server->host;
        if (in_array($server->host, ['0.0.0.0', '::'], true)) {
            $ipv6 = !self::acceptsIpv4($server->ports[0]);
            try {
                $localIp = \libspech\Network\network::getLocalIp($ipv6 ? 6 : 4);
            } catch (\RuntimeException $e) {
                $localIp = $ipv6 ? '::1' : '127.0.0.1';
            }
        }
        self::publishRuntimeAddress($server, $prefix, $localIp);
        foreach (self::listenAddresses($server) as $address) {
            $urlHost = str_contains($address, ':') ? '[' . str_replace('%', '%25', $address) . ']' : $address;
            print $cli->color(sprintf("O servidor está sendo executado no endereço => %s%s:%s%s", $prefix, $urlHost, $server->port, PHP_EOL), "yellow");
        }
        
        self::tick($server, 10000);
    }
}
