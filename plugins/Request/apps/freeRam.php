<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class freeRam
{
    public static function api(Request $request, Response $response): bool
    {
        security::verifyToken($request) ?: security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $output = [];
        $exitCode = 0;

        exec(
            '/usr/bin/sudo -n /usr/local/sbin/filemanager-drop-caches 2>&1',
            $output,
            $exitCode
        );

        return $response->end(json_encode([
            'success' => $exitCode === 0,
            'information' => $exitCode === 0
                ? 'Cache do sistema liberado'
                : 'Não foi possível liberar o cache',
            'error' => $exitCode === 0 ? null : implode("\n", $output),
        ]));
    }
}
