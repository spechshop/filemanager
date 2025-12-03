<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class renameItem
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $data = $request->get;
        $path = str_replace('//', '/',  $data['path']);
        $newPath = str_replace('//', '/',  $data['newName']);
        if (file_exists($path)) {
            if (utilsFunction::renameItem($path, $newPath)) {
                return $response->end(json_encode([
                    'success' => true,
                    'message' => 'Rename success'
                ]));
            } else return $response->end(json_encode([
                'success' => false,
                'message' => 'Rename failed'
            ]));

        } else return $response->end(json_encode([
            'success' => false,
            'message' => 'File not found'
        ]));

    }
}