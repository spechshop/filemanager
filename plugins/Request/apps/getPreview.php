<?php
namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getPreview
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $path = $request->get['path'];
        $realPath = $path;

        if (file_exists($realPath)) {
            // Caminho temporário para thumbnail
            $thumbPath = sys_get_temp_dir() . '/thumb_' . md5($realPath) . '.jpg';

            // Comando para gerar thumbnail em 1s do vídeo
            $cmd = sprintf(
                'ffmpeg -y -i %s -ss 00:00:01.000 -vframes 1 -f image2 %s 2>&1',
                escapeshellarg($realPath),
                escapeshellarg($thumbPath)
            );

            exec($cmd, $out, $code);

            if ($code === 0 && file_exists($thumbPath)) {
                $response->header('Content-Type', 'image/jpeg');
                $response->sendfile($thumbPath);
                // Opcionalmente, apague depois (ou deixe o GC do SO cuidar)
                unlink($thumbPath);
            } else {
                $response->status(500);
                $response->end("Erro ao gerar thumbnail.\n" . implode("\n", $out));
            }
        } else {
            $response->status(404);
            $response->end("Arquivo não encontrado.");
        }
    }
}
