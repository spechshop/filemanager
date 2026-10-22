<?php
namespace plugins\websocket;
use plugins\Request\securityPolicy;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class OpenConnection
{
    public static function open(Server $server, Request $request): void
    {
        $socket = ltrim(explode('?', $request->server['request_uri'] ?? '/', 2)[0], '/');
        $owner = securityPolicy::socketOwner($socket);
        if (!securityPolicy::originAllowed($request, true) || !securityPolicy::validToken($owner)) {
            securityPolicy::log('connection_denied', 'websocket');
            $server->disconnect($request->fd, 1008, 'Unauthorized');
            return;
        }
        $fd = $request->fd;
        $identity = bin2hex(random_bytes(16));
        $GLOBALS['fdToToken'][$fd] = $socket;
        $GLOBALS['websocketConnections'][$fd] = ['token' => $socket, 'clientToken' => $owner, 'identity' => $identity];
        // Active and idle connections must be revoked, not only the next incoming frame.
        $timer = \Swoole\Timer::tick(1000, static function () use ($server, $fd, $owner, $identity): void {
            if (($GLOBALS['websocketConnections'][$fd]['identity'] ?? '') !== $identity) return;
            $valid = securityPolicy::validToken($owner);
            if (($GLOBALS['websocketConnections'][$fd]['identity'] ?? '') !== $identity) return;
            if (!$valid) {
                securityPolicy::log('session_revoked', 'websocket');
                $server->disconnect($fd, 1008, 'Session expired');
                \plugins\server::close($server, $fd);
            }
        });
        $GLOBALS['websocketConnections'][$fd]['timer'] = $timer;
    }

    protected static function clientTokenFromSocketToken(string $socketToken): string
    {
        return securityPolicy::socketOwner($socketToken);
    }

    protected static function validToken(string $token): bool
    {
        return securityPolicy::validToken($token);
    }
}
