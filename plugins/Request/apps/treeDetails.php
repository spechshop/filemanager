<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class treeDetails
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $directory = $request->get['dir'] ?? securityPolicy::initialPath(securityPolicy::requestToken($request));

        $owner = securityPolicy::requestToken($request);
        $path = $directory === '/' && securityPolicy::roots($owner) !== ['/'] ? securityPolicy::initialPath($owner) : securityPolicy::path($directory, $owner);

        $response->header('X-FileManager-Root', securityPolicy::roots($owner)[0]);
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- canonical workspace path, validated above and covered by escape tests
        if (is_dir($path)) {
            $tree = self::getDirTree($path, $owner);
            $response->header("Content-Type", "application/json");
            return $response->end(json_encode($tree));
        } else {
            $response->status(404);
            return $response->end("Directory not found");
        }
    }

    public static function getDirTree(string $dir, string $owner): array
    {
        $result = [];
        foreach (safeFiles::listing($dir, $owner) as $item) {
            if ($item['directory']) $result[$item['name']] = [];
            else $result[] = $item['name'];
        }
        return $result;
    }
}
