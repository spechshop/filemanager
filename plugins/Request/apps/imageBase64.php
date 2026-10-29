<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;
use plugins\Extension\utilsFunction;

class imageBase64
{
    /**
     * Obtém o tipo MIME do arquivo com base na extensão.
     *
     * @param string $filePath Caminho do arquivo.
     * @return string Tipo MIME do arquivo.
     */
    public static function getMimeType($filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            "jpg" => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png" => "image/png",
            "gif" => "image/gif",
            "webp" => "image/webp",
            "bmp" => "image/bmp",
            "svg" => "image/svg+xml",
            "ico" => "image/x-icon",
            "mp4" => "video/mp4",
            "avi" => "video/x-msvideo",
            "webm" => "video/webm",
            "ogg" => "video/ogg",
            "mp3" => "audio/mpeg",
            "wav" => "audio/wav",
            "flac" => "audio/flac",
        ];

        return $mimeTypes[$extension] ?? "application/octet-stream";
    }

    /**
     * Manipula a requisição para retornar uma imagem em formato base64.
     *
     * @param Request $request
     * @param Response $response
     */
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $path = $request->get["path"] ?? null;
        if (!$path || !file_exists($path)) {
            $response->status(404);
            return $response->end();
        }

        $realPath = securityPolicy::path($path, securityPolicy::requestToken($request));
        $response->header('Content-Security-Policy', "sandbox; default-src 'none'");
        $response->header('X-Content-Type-Options', 'nosniff');
        $owner = securityPolicy::requestToken($request);
        $start = 0; $end = null;
        $range = $request->header['range'] ?? '';
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d+)-(\d*)$/D', $range, $matches)) { $response->status(416); return $response->end(); }
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? null : (int) $matches[2];
            if ($end !== null && $end < $start) { $response->status(416); return $response->end(); }
        }
        $operation = safeFiles::reader($realPath, $owner, $start, $end === null ? null : $end - $start + 1);
        $cancel = false;
        try {
            $size = $operation['size'];
            $end ??= $size - 1;
            if ($range !== '' && ($start >= $size || $end >= $size)) {
                $cancel = true; $response->status(416); return $response->end();
            }
            $mime = self::getMimeType($realPath);
            if ($mime === 'application/octet-stream' || $mime === 'image/svg+xml') $response->header('Content-Disposition', 'attachment');
            $response->header('Accept-Ranges', 'bytes');
            $response->header('Content-Type', $mime);
            $response->header('Content-Length', (string) max(0, $end - $start + 1));
            if ($range !== '') {
                $response->status(206);
                $response->header('Content-Range', "bytes $start-$end/$size");
            }
            while (!feof($operation['stream'])) {
                $buffer = fread($operation['stream'], 262144);
                if (!securityPolicy::validToken($owner) || $buffer === false || $buffer === '') { $cancel = true; break; }
                if ($response->write($buffer) === false) { $cancel = true; break; }
            }
            return $response->end();
        } finally { safeFiles::close($operation, $cancel); }
    }
}
