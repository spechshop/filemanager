<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;

class deleteMultipleFiles
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Content-Type', 'application/json');
        $owner = securityPolicy::requestToken($request);
        $files = $request->post['files'] ?? [];
        return $response->end(json_encode(recycleBin::move($files, $owner), JSON_UNESCAPED_UNICODE));
    }
}
