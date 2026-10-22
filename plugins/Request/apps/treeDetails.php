<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class treeDetails
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::authorize($request, $response)) return true;
        $directory = $request->get['dir'] ?? '/';

        $owner = securityPolicy::requestToken($request);
        $path = $directory === '/' ? securityPolicy::roots($owner)[0] : securityPolicy::path($directory, $owner);

        $response->header('X-FileManager-Root', securityPolicy::roots($owner)[0]);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- canonical workspace path, validated above and covered by escape tests
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

            if (is_link($dir . DIRECTORY_SEPARATOR . $value)) continue;
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
