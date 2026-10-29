<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class schemaPath
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Content-Type', 'application/json');

        $owner = securityPolicy::requestToken($request);
        $schema = array_column(safeFiles::listing($request->get['path'] ?? securityPolicy::initialPath($owner), $owner), 'path');
        return $response->end(json_encode(['success' => true, 'schema' => $schema]));
    }
}