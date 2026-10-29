<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class uploadFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $token = securityPolicy::requestToken($request);
        $target = securityPolicy::path($request->get['path'] ?? '', $token);
        $files = $request->files['files'] ?? [];
        // Check the entire batch before moving the first upload.
        foreach ($files as $file) {
            securityPolicy::path($target . '/' . securityPolicy::filename($file['name'] ?? null), $token, false);
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $response->status(400);
                return $response->end(json_encode(['success' => false, 'message' => 'Invalid upload']));
            }
        }
        foreach ($files as $file) {
            if (!securityPolicy::validToken($token)) return security::invalidToken($response);
            $path = securityPolicy::path($target . '/' . $file['name'], $token, false);
            safeFiles::upload($file['tmp_name'], $path, $token);
            unlink($file['tmp_name']);
        }
        return $response->end(json_encode(['success' => true, 'information' => 'ok']));
    }
}
