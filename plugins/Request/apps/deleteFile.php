<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class deleteFile
{
    public static function api(Request $request, Response $response)
    {
        security::verifyToken($request) ?: security::invalidToken($response);
        $response->header('Content-Type', 'application/json');

        $dirDelete =  escapeshellarg($request->get['path']);
        var_dump("rm -rf $dirDelete");

        
        shell_exec("rm -rf $dirDelete");

        return $response->end(json_encode([
            'success' => true,
            'information' => 'ok'
        ]));
    }
}