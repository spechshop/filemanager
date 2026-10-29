<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class imageBase643
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $path = $request->get['path'];
        $realPath = $path;
        if (file_exists($realPath)) {
            $response->header('Content-Type', 'image/png');
            // fechar output buffering
            $response->write(safeFiles::read($realPath, securityPolicy::requestToken($request)));
            //$response->close();

        } else {
            $response->status(404);
            $response->end();
        }
    }
}