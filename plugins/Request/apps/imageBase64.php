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
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $path = $request->get["path"] ?? null;
        if (!$path || !file_exists($path)) {
            $response->status(404);
            return $response->end();
        }

        $realPath = realpath($path);
        $fileSize = filesize($realPath);
        $mime = self::getMimeType($realPath);
        $start = 0;
        $end = $fileSize - 1;

        $response->header("Accept-Ranges", "bytes");
        $response->header("Content-Type", $mime);

        // Verifica se é uma requisição parcial (stream)
        if (isset($request->header["range"]) && preg_match("/bytes=(\d+)-(\d*)/", $request->header["range"], $matches)) {
            $start = intval($matches[1]);
            if (isset($matches[2]) && $matches[2] !== "") {
                $end = intval($matches[2]);
            }

            $length = $end - $start + 1;
            $response->status(206);
            $response->header("Content-Range", "bytes $start-$end/$fileSize");
            $response->header("Content-Length", $length);

            $fp = fopen($realPath, "rb");
            fseek($fp, $start);

            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $readLength = min(8192, $remaining);
                $buffer = fread($fp, $readLength);
                if ($buffer === false) {
                    break;
                }
                $response->write($buffer);
                $remaining -= strlen($buffer);
            }
            fclose($fp);
            return;
        }

        // Envia arquivo completo em blocos (evita memory_limit)
        $response->header("Content-Length", $fileSize);

        $fp = fopen($realPath, "rb");
        while (!feof($fp)) {
            $buffer = fread($fp, 8192);
            if ($buffer === false) {
                break;
            }
            $response->write($buffer);
        }
        fclose($fp);
    }
}
