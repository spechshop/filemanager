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
        $path = $request->get['path'] ?? '';
        securityPolicy::entryPath($path, $token);
        safeFiles::remove($path, $token);
        securityPolicy::log('file_deleted');
        return $response->end(json_encode(['success' => true, 'information' => 'ok']));
    }
}
