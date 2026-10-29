<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class getPreview
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $owner = securityPolicy::requestToken($request);
        $input = safeFiles::snapshot($request->get['path'], $owner);
        $thumbnail = null;
        try {
            $thumbnail = securityPolicy::temporary('fm-thumb-');
            if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
            $pipes = [];
            $process = proc_open(['ffmpeg', '-nostdin', '-protocol_whitelist', 'file,pipe', '-format_whitelist',
                'mov,matroska,avi,mpegts,mpeg,flv,asf,rm,mp3,wav,ogg,flac,image2,webp_pipe,png_pipe,jpeg_pipe,h264,hevc,av1,ivf',
                '-y', '-i', $input, '-ss', '00:00:01.000', '-vframes', '1', '-c:v', 'mjpeg', '-f', 'image2', $thumbnail],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
            $ok = is_resource($process) && proc_close($process) === 0;
            if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
            if (!$ok) { $response->status(500); return $response->end('Erro ao gerar thumbnail.'); }
            $response->header('Content-Type', 'image/jpeg');
            return $response->end(file_get_contents($thumbnail));
        } finally { @unlink($input); if (is_string($thumbnail)) @unlink($thumbnail); }
    }
}
