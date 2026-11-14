<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class getPreview
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $response->header('Cache-Control', 'private, no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
        $owner = securityPolicy::requestToken($request);
        // Read through the filesystem broker. Bound input before creating any snapshot.
        $operation = safeFiles::reader($request->get['path'], $owner);
        $input = null; $thumbnail = null;
        try {
            $extension = strtolower(pathinfo($request->get['path'], PATHINFO_EXTENSION));
            $video = in_array($extension, ['mp4', 'webm', 'ogg'], true);
            if ($operation['size'] > ($video ? 536870912 : 33554432)) { $response->status(413); return $response->end(); }
            $input = securityPolicy::temporary('fm-preview-');
            $output = fopen($input, 'wb');
            try { stream_copy_to_stream($operation['stream'], $output); } finally { fclose($output); }
        } catch (\Throwable $error) { if (is_string($input)) @unlink($input); throw $error; } finally { safeFiles::close($operation); }
        try {
            if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
            if (!$video) {
                if ($extension === 'svg') {
                    // Fail closed: allow a static SVG subset, never entities, scripts, links or external resources.
                    $xml = file_get_contents($input);
                    if (strlen($xml) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) || !class_exists(\DOMDocument::class)) { $response->status(415); return $response->end(); }
                    $doc = new \DOMDocument();
                    if (!@$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement->localName !== 'svg') { $response->status(415); return $response->end(); }
                    foreach ($doc->getElementsByTagName('*') as $node) {
                        if (!in_array($node->localName, ['svg','g','path','rect','circle','ellipse','line','polyline','polygon','text','tspan','defs','linearGradient','radialGradient','stop','title','desc'], true)) { $response->status(415); return $response->end(); }
                        foreach ($node->attributes as $attribute) {
                            if (!in_array($attribute->nodeName, ['xmlns','viewBox','width','height','x','y','x1','x2','y1','y2','cx','cy','r','rx','ry','d','points','fill','stroke','stroke-width','opacity','fill-opacity','stroke-opacity','transform','id','offset','stop-color','stop-opacity','font-size','text-anchor'], true)
                                || preg_match('/url\s*\(|https?:|data:|javascript:/i', $attribute->value)) { $response->status(415); return $response->end(); }
                        }
                    }
                    $response->header('Content-Type', 'image/svg+xml');
                    return $response->end($doc->saveXML($doc->documentElement));
                }
                $info = @getimagesize($input);
                // Bound decoded memory as well as compressed file size.
                if (!$info || $info[0] * $info[1] > 24000000 || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)) { $response->status(415); return $response->end(); }
                $image = @imagecreatefromstring(file_get_contents($input));
                if ($image) {
                try {
                    $ratio = min(1, 480 / $info[0], 360 / $info[1]);
                    $thumb = imagescale($image, max(1, (int) round($info[0] * $ratio)), max(1, (int) round($info[1] * $ratio)));
                    if (!$thumb) { $response->status(415); return $response->end(); }
                    try { imagesavealpha($thumb, true); ob_start(); imagepng($thumb); $bytes = ob_get_clean(); } finally { unset($thumb); }
                } finally { unset($image); }
                if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
                $response->header('Content-Type', 'image/png');
                return $response->end($bytes);
                }
            }
            $thumbnail = securityPolicy::temporary('fm-thumb-');
            $pipes = [];
            $process = proc_open(['ffmpeg', '-nostdin', '-threads', '1', '-protocol_whitelist', 'file,pipe', '-format_whitelist',
                'mov,matroska,avi,mpegts,mpeg,flv,asf,rm,ogg,jpeg_pipe,png_pipe,webp_pipe,bmp_pipe,gif,image2,ico', '-y', '-i', $input,
                '-frames:v', '1', '-vf', 'scale=480:360:force_original_aspect_ratio=decrease', '-threads', '1', '-c:v', 'mjpeg', '-f', 'image2', $thumbnail],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) { $response->status(415); return $response->end(); }
            $deadline = microtime(true) + 8;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) > $deadline || !securityPolicy::validToken($owner) || (method_exists($response, 'isWritable') && !$response->isWritable())) { proc_terminate($process, 9); break; }
                if (\Swoole\Coroutine::getCid() >= 0) \Swoole\Coroutine::sleep(0.02); else usleep(20000);
            } while (true);
            proc_close($process);
            if (!securityPolicy::validToken($owner)) throw new \DomainException('revoked');
            if (empty($status) || $status['running'] || $status['exitcode'] !== 0 || !filesize($thumbnail)) { $response->status(415); return $response->end(); }
            $response->header('Content-Type', 'image/jpeg');
            return $response->end(file_get_contents($thumbnail));
        } finally { if (is_string($input)) @unlink($input); if (is_string($thumbnail)) @unlink($thumbnail); }
    }
}
