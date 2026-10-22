<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class schemaPath
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;
        $response->header('Content-Type', 'application/json');

        $schema = appController::listFilesAndDirs(securityPolicy::roots(securityPolicy::requestToken($request))[0]);
        return $response->end(json_encode(['success' => true, 'schema' => $schema]));
    }
}