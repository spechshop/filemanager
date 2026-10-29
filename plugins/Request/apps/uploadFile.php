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
        $layout = securityPolicy::uploadLayout($files, $request->post ?? [], $target, $token);
        if ($files === [] && $layout['directories'] === []) {
            $response->status(400);
            return $response->end(json_encode(['success' => false, 'message' => 'Empty upload']));
        }
        // Check the entire batch before moving the first upload.
        $uploads = [];
        foreach (array_values($files) as $index => $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $response->status(400);
                return $response->end(json_encode(['success' => false, 'message' => 'Invalid upload']));
            }
            $uploads[] = ['source' => $file['tmp_name'], 'target' => $layout['targets'][$index]];
        }
        safeFiles::uploadMany($uploads, $token, $target, $layout['directories']);
        foreach ($files as $file) {
            unlink($file['tmp_name']);
        }
        return $response->end(json_encode(['success' => true, 'information' => 'ok']));
    }
}
