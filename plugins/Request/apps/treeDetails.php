<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use plugins\Start\cache;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;

class treeDetails
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);
        $_GET = $request->get;
        $_POST = $request->post;
        $dir = isset($params['dir']) ? urldecode($params['dir']) : '/';


        $path = $_GET['dir'];
        var_dump($path, $_GET);


        if (is_dir($path)) {
            $tree = self::getDirTree($path);
            $response->header("Content-Type", "application/json");
            return $response->end(json_encode($tree));
        } else {
            $response->status(404);
            return $response->end("Directory not found");
        }
    }

    public static function getDirTree($dir): array
    {
        $result = [];
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            if ($value === '.' || $value === '..') {
                continue;
            }

            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (is_dir($path)) {
                $result[$value] = [];
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }
}
