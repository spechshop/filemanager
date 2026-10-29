<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class downloadFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $filePath = str_replace('//', '/',  $request->get['path']);
        if (!file_exists($filePath)) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode([
                'success' => false,
                'information' => 'file not found'
            ]));
        }

        $owner = securityPolicy::requestToken($request);
        $operation = safeFiles::reader($filePath, $owner);
        $response->header('Content-Type', 'application/octet-stream');
        $response->header('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode(basename($filePath)));
        $response->header('Content-Length', (string) $operation['size']);
        try {
            while (!feof($operation['stream'])) {
                $data = fread($operation['stream'], 262144);
                if (!securityPolicy::validToken($owner) || $data === false || $data === '') break;
                if ($response->write($data) === false) break;
            }
        } finally { safeFiles::close($operation); }
        $response->end();
    }

}