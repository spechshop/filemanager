<?php
declare(strict_types=1);
namespace plugins\Request;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

final class tunnelDirectPeer
{
    public Channel $queue;
    public Channel $credits;
    public bool $readEnd = false;
    public bool $writeEnd = false;
    public function __construct(public Socket $socket)
    {
        $this->queue = new Channel(16);
        // Eight outstanding data packets per direction keep slow peers bounded.
        $this->credits = new Channel(8);
        for ($i = 0; $i < 8; $i++) $this->credits->push(true);
    }
}

final class tunnelDirectSession
{
    public array $peers = [];
    public int $next = 0;
    public int $lastSeen;
    public ?int $timer = null;
    public function __construct(public Socket $listener, public string $secret, public string $session, public string $url)
    {
        $this->lastSeen = time();
    }
}

/** Packets: open=1, data=2, FIN=3, reset=4, ping=5, pong=6, data credit=7; then a uint32 stream ID. */
final class tunnelDirect
{
    private static array $agents = [];

    public static function has(int $fd): bool { return isset(self::$agents[$fd]); }

    public static function open(Server $server, Request $request): void
    {
        $fd = $request->fd;
        $authorization = $request->header['authorization'] ?? '';
        $secret = is_string($authorization) && preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $match) ? $match[1] : '';
        $session = $request->header['x-tunnel-session'] ?? '';
        $listener = null;
        try {
            if (!securityPolicy::originAllowed($request) || !is_string($session)) throw new \DomainException('Unauthorized');
            $entry = tunnelManager::authorizeDirect($secret, $session);
            // Follow the File Manager's bind address; never add a public listener to a loopback-only installation.
            $host = $server->host;
            $listener = new Socket(str_contains($host, ':') ? AF_INET6 : AF_INET, SOCK_STREAM, SOL_TCP);
            $listener->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            if (!$listener->bind($host, $entry['remotePort']) || !$listener->listen(128)) {
                tunnelManager::agent($secret, ['session' => $session, 'status' => 'error', 'url' => '',
                    'message' => 'Não foi possível abrir a porta no host do File Manager. Verifique se já está em uso.']);
                throw new \RuntimeException('Port unavailable');
            }
            tunnelManager::authorizeDirect($secret, $session);
            $state = new tunnelDirectSession($listener, $secret, $session, tunnelManager::directUrl($entry));
            self::$agents[$fd] = $state;
            self::heartbeat($server, $fd, $state);
            $state->timer = \Swoole\Timer::tick(1000, static function () use ($server, $fd, $state): void {
                try {
                    tunnelManager::authorizeDirect($state->secret, $state->session);
                    if (time() - $state->lastSeen > 25) throw new \DomainException('Agent heartbeat expired');
                } catch (\Throwable $error) {
                    if ((self::$agents[$fd] ?? null) === $state) {
                        self::close($fd);
                        $server->disconnect($fd, 1008, 'Tunnel disabled');
                    }
                }
            });
            Coroutine::create(static function () use ($server, $fd, $state): void {
                while ((self::$agents[$fd] ?? null) === $state) {
                    $socket = $state->listener->accept(-1);
                    if ($socket === false) break;
                    if (count($state->peers) >= 64 || (self::$agents[$fd] ?? null) !== $state) { $socket->close(); continue; }
                    $id = ++$state->next;
                    if ($id > 0xffffffff) { $socket->close(); break; }
                    $peer = new tunnelDirectPeer($socket);
                    $state->peers[$id] = $peer;
                    if (!self::send($server, $fd, $state, 1, $id)) { self::drop($state, $id); break; }
                    Coroutine::create(static function () use ($server, $fd, $state, $peer, $id): void {
                        while (($state->peers[$id] ?? null) === $peer) {
                            $packet = $peer->queue->pop(-1);
                            if ($packet === false) return;
                            if ($packet[0] === 3) {
                                $peer->socket->shutdown(1);
                                $peer->writeEnd = true;
                                if ($peer->readEnd) self::drop($state, $id);
                                return;
                            }
                            if ($peer->socket->sendAll($packet[1], 30) !== strlen($packet[1]) || !self::send($server, $fd, $state, 7, $id)) break;
                        }
                        if (isset($state->peers[$id])) { self::send($server, $fd, $state, 4, $id); self::drop($state, $id); }
                    });
                    Coroutine::create(static function () use ($server, $fd, $state, $peer, $id): void {
                        while (($state->peers[$id] ?? null) === $peer) {
                            if ($peer->credits->pop(-1) === false) return;
                            $data = $peer->socket->recv(32768, -1);
                            if ($data === '') {
                                $peer->readEnd = true;
                                self::send($server, $fd, $state, 3, $id);
                                if ($peer->writeEnd) self::drop($state, $id);
                                return;
                            }
                            if ($data === false || !self::send($server, $fd, $state, 2, $id, $data)) break;
                        }
                        if (isset($state->peers[$id])) { self::send($server, $fd, $state, 4, $id); self::drop($state, $id); }
                    });
                }
                if ((self::$agents[$fd] ?? null) === $state) { self::close($fd); $server->disconnect($fd); }
            });
        } catch (\Throwable $error) {
            if (!$error instanceof \DomainException) error_log('[filemanager] Direct tunnel: ' . $error->getMessage());
            if (self::has($fd)) self::close($fd);
            elseif ($listener instanceof Socket) $listener->close();
            $server->disconnect($fd, 1008, 'Direct tunnel unavailable');
        }
    }

    private static function send(Server $server, int $fd, tunnelDirectSession $state, int $type, int $id, string $data = ''): bool
    {
        $deadline = microtime(true) + 30;
        while ((self::$agents[$fd] ?? null) === $state && $server->isEstablished($fd)) {
            if ($server->push($fd, pack('CN', $type, $id) . $data, WEBSOCKET_OPCODE_BINARY)) return true;
            if ($server->getLastError() !== SWOOLE_ERROR_OUTPUT_BUFFER_OVERFLOW || microtime(true) >= $deadline) return false;
            Coroutine::sleep(0.01);
        }
        return false;
    }

    private static function heartbeat(Server $server, int $fd, tunnelDirectSession $state): void
    {
        tunnelManager::agent($state->secret, ['session' => $state->session, 'status' => 'online', 'url' => $state->url]);
        $state->lastSeen = time();
        if (!self::send($server, $fd, $state, 6, 0, $state->url)) throw new \RuntimeException('Agent disconnected');
    }

    public static function message(Server $server, Frame $frame): void
    {
        $state = self::$agents[$frame->fd] ?? null;
        if ($state === null) return;
        try {
            if ($frame->opcode !== WEBSOCKET_OPCODE_BINARY || strlen($frame->data) < 5 || strlen($frame->data) > 32773) throw new \DomainException('Invalid frame');
            $type = ord($frame->data[0]);
            $id = unpack('N', substr($frame->data, 1, 4))[1];
            if ($type === 5 && $id === 0 && strlen($frame->data) === 5) { self::heartbeat($server, $frame->fd, $state); return; }
            if (!in_array($type, [2, 3, 4, 7], true) || $id === 0 || ($type !== 2 && strlen($frame->data) !== 5)) throw new \DomainException('Invalid stream');
            if (!isset($state->peers[$id])) return;
            if ($type === 4) { self::drop($state, $id); return; }
            $peer = $state->peers[$id];
            if ($type === 7) {
                if (!$peer->credits->push(true, 0.001)) { self::send($server, $frame->fd, $state, 4, $id); self::drop($state, $id); }
                return;
            }
            if ($peer->writeEnd || !$peer->queue->push([$type, substr($frame->data, 5)], 0.001)) {
                self::send($server, $frame->fd, $state, 4, $id);
                self::drop($state, $id);
            }
        } catch (\Throwable $error) {
            if ((self::$agents[$frame->fd] ?? null) === $state) {
                self::close($frame->fd);
                $server->disconnect($frame->fd, 1008, 'Tunnel disabled');
            }
        }
    }

    private static function drop(tunnelDirectSession $state, int $id): void
    {
        $peer = $state->peers[$id] ?? null;
        if ($peer === null) return;
        unset($state->peers[$id]);
        $peer->queue->close();
        $peer->credits->close();
        $peer->socket->close();
    }

    public static function close(int $fd): void
    {
        $state = self::$agents[$fd] ?? null;
        if ($state === null) return;
        unset(self::$agents[$fd]);
        if ($state->timer !== null) \Swoole\Timer::clear($state->timer);
        $state->listener->close();
        foreach (array_keys($state->peers) as $id) self::drop($state, $id);
        try { tunnelManager::agent($state->secret, ['session' => $state->session, 'status' => 'offline', 'url' => '']); } catch (\Throwable $error) { /* Already revoked. */ }
    }

    public static function stopWorker(): void
    {
        foreach (array_keys(self::$agents) as $fd) self::close($fd);
    }
}
