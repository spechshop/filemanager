<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;
        $path = securityPolicy::path($request->post['path'] ?? $request->get['path'] ?? '', securityPolicy::requestToken($request));
        $folder = file_get_contents($path);

        // detectar se contem caracteres especiais, se tiver usa um utf8_encode
        $encoded = false;
        if (mb_detect_encoding($folder, "UTF-8", true) === false) {
            $folder = base64_encode($folder);
            $encoded = true;
        }

        $fileEscaped = $folder;

        return $response->end(
            json_encode([
                "success" => true,
                "information" => $fileEscaped,
                "encoded" => $encoded,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
