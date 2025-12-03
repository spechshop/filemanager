<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class downloadFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $filePath = str_replace('//', '/',  $request->get['path']);
        if (!file_exists($filePath)) return $response->end(json_encode([
            'success' => false,
            'information' => 'file not found'
        ]));
        $response ->header('Content-Encoding', false);
        $response->header('Content-Length', filesize($filePath));
        $response->header('Content-Type', 'application/octet-stream');
        $response->header('Content-Disposition', 'attachment; filename="' . basename($filePath) . '"');
        $response->sendfile($filePath);
    }

}