<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class imageBase643
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $path = $request->get['path'];
        $realPath = $path;
        if (file_exists($realPath)) {
            $response->header('Content-Type', 'image/png');
            // fechar output buffering
            $response->write(file_get_contents($realPath));
            //$response->close();

        } else {
            $response->status(404);
            $response->end();
        }
    }
}