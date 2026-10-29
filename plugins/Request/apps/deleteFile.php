<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class deleteFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $token = securityPolicy::requestToken($request);
        $path = $request->post['path'] ?? $request->get['path'] ?? '';
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(recycleBin::move([$path], $token), JSON_UNESCAPED_UNICODE));
    }
}
