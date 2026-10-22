<?php
namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getPreview
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;

        $path = $request->get['path'];
        $realPath = $path;

        if (file_exists($realPath)) {
            // Caminho temporário para thumbnail
            $thumbPath = tempnam(sys_get_temp_dir(), 'fm-thumb-');
            if ($thumbPath === false) throw new \RuntimeException('thumbnail');

            // Comando para gerar thumbnail em 1s do vídeo
            $cmd = sprintf(
                'ffmpeg -nostdin -protocol_whitelist file,pipe -format_whitelist mov,matroska,avi,mpegts,mpeg,flv,asf,rm,mp3,wav,ogg,flac,image2,webp_pipe,png_pipe,jpeg_pipe,h264,hevc,av1,ivf -y -i %s -ss 00:00:01.000 -vframes 1 -c:v mjpeg -f image2 %s 2>&1',
                escapeshellarg($realPath),
                escapeshellarg($thumbPath)
            );

            exec($cmd, $out, $code);

            if ($code === 0 && file_exists($thumbPath)) {
                $response->header('Content-Type', 'image/jpeg');
                $response->end(file_get_contents($thumbPath));
                // Opcionalmente, apague depois (ou deixe o GC do SO cuidar)
                unlink($thumbPath);
            } else {
                $response->status(500);
                $response->end("Erro ao gerar thumbnail.");
                if (file_exists($thumbPath)) unlink($thumbPath);
            }
        } else {
            $response->status(404);
            $response->end("Arquivo não encontrado.");
        }
    }
}
