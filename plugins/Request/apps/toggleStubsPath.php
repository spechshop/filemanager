<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class toggleStubsPath
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $response->header('Content-Type', 'application/json');
        $path = $request->get['path'];
        if (!file_exists('stubs-paths.json')) {
            file_put_contents('stubs-paths.json', '[]');
        }
        $stubsPaths = json_decode(file_get_contents('stubs-paths.json'), true);
        if (in_array($path, $stubsPaths)) {
            $stubsPaths = array_diff($stubsPaths, [$path]);
            $action = 'removed';
        } else {
            $stubsPaths[] = $path;
            $action = 'added';
        }
        if (file_put_contents('stubs-paths.json', json_encode($stubsPaths, JSON_PRETTY_PRINT))) {
            sleep(1);
            \co::exec('php r.php');
            
            return $response->end(json_encode([
                'success' => true,
                'action' => $action,
                'path' => $path
            ]));
        }
    }
}