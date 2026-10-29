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
        $uploads = [];
        foreach ($files as $file) {
            $path = securityPolicy::path($target . '/' . securityPolicy::filename($file['name'] ?? null), $token, false);
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $response->status(400);
                return $response->end(json_encode(['success' => false, 'message' => 'Invalid upload']));
            }
            $uploads[] = ['source' => $file['tmp_name'], 'target' => $path];
        }
        safeFiles::uploadMany($uploads, $token);
        foreach ($files as $file) {
            unlink($file['tmp_name']);
        }
        return $response->end(json_encode(['success' => true, 'information' => 'ok']));
    }
}
