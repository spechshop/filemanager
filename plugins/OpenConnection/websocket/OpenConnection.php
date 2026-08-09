<?php

namespace plugins\websocket;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

class OpenConnection
{
    public static function open(Server $server, Request $request): void
    {
        $uri = (string) ($request->server['request_uri'] ?? '/');
        $socketToken = ltrim(explode('?', $uri, 2)[0], '/');
        $clientToken = self::clientTokenFromSocketToken($socketToken);

        // O websocket de métricas (SSD/RAM/CPU) conecta em "/" e autentica
        // no primeiro frame com {token}. Terminais/LSP/etc usam "/{token}-...".
        if ($socketToken !== '') {
            if ($clientToken === '' || !self::validToken($clientToken)) {
                $server->close($request->fd);
                return;
            }
        }

        if (!isset($GLOBALS['fdToToken'])) {
            $GLOBALS['fdToToken'] = [];
        }
        if (!isset($GLOBALS['websocketConnections'])) {
            $GLOBALS['websocketConnections'] = [];
        }

        $GLOBALS['fdToToken'][$request->fd] = $socketToken;
        $GLOBALS['websocketConnections'][$request->fd] = [
            'token' => $socketToken,
            'clientToken' => $clientToken,
            'openedAt' => time(),
        ];
    }

    protected static function clientTokenFromSocketToken(string $socketToken): string
    {
        if ($socketToken === '') {
            return '';
        }

        $parts = explode('-', $socketToken);
        return $parts[0] ?? '';
    }

    protected static function validToken(string $token): bool
    {
        if ($token === '' || !key_exists($token, cache::global()['dataKeys'] ?? [])) {
            return false;
        }

        $expires = cache::global()['dataKeys'][$token]['expire'] ?? 0;
        return time() < (int) $expires;
    }
}
