<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class decompressFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $token = securityPolicy::requestToken($request);
        $data = ($request->post ?: json_decode($request->rawContent(), true)) ?: [];
        $root = securityPolicy::path($data['path'] ?? '', $token);
        $name = securityPolicy::filename($data['namefile'] ?? null);
        $archive = securityPolicy::path($root . '/' . $name, $token);
        $target = ($data['type'] ?? '') === 'yes' ? $root . '/' . securityPolicy::filename(pathinfo($name, PATHINFO_FILENAME)) : $root;
        return $response->end(json_encode(safeFiles::extract($archive, $target, $token)));
    }
}
