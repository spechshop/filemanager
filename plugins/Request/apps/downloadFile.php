<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class downloadFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);

        $filePath = str_replace('//', '/',  $request->get['path']);
        if (!file_exists($filePath)) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode([
                'success' => false,
                'information' => 'file not found'
            ]));
        }

        $fileSize = filesize($filePath);
        $chunkSize = 8192; // 8KB por chunk

        $response->header('Content-Type', 'application/octet-stream');
        $response->header('Content-Disposition', 'attachment; filename="' . basename($filePath) . '"');
        $response->header('Content-Length', $fileSize);

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode([
                'success' => false,
                'information' => 'failed to open file'
            ]));
        }

        $offset = 0;
        while ($offset < $fileSize) {
            fseek($handle, $offset, SEEK_SET);
            $data = fread($handle, $chunkSize);
            if ($data === false) break;

            $response->write($data);
            $offset += strlen($data);

            // Libera memória
            unset($data);
        }

        fclose($handle);
        $response->end();
    }

}