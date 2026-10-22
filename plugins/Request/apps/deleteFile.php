<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class deleteFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;
        $token = securityPolicy::requestToken($request);
        safeFiles::remove(securityPolicy::path($request->get['path'] ?? '', $token, false), $token);
        securityPolicy::log('file_deleted');
        return $response->end(json_encode(['success' => true, 'information' => 'ok']));
    }
}
