<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class schemaPath
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Encoding', 'gzip');
        $response->header('Content-Type', 'application/json');

        $schema = appController::listFilesAndDirs('files');
        var_dump($schema);
    }
}