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
        $command = 'sudo sync; echo 3 | sudo tee /proc/sys/vm/drop_caches';
        shell_exec($command);
        return $response->end(json_encode([
            'success' => true,
            'information' => 'ok'
        ]));
    }
}