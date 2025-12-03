<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getFile
{
    public static function api(Request $request, Response $response)
    {
        $_GET = $request->get;
        $_POST = $request->post;

        if (!empty($_GET["tokenBrowser"])) {
            $tokenBrowser = $_GET["tokenBrowser"];
        }
        if (!empty($_POST["tokenBrowser"])) {
            $tokenBrowser = $_POST["tokenBrowser"];
        }
        if (!empty($_GET["path"])) {
            $path = $_GET["path"];
        }
        if (!empty($_POST["path"])) {
            $path = $_POST["path"];
        }
        if (empty($tokenBrowser)) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "message" => "Identifier not found",
                ])
            );
        } elseif (!key_exists($tokenBrowser, cache::global()["dataKeys"])) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "message" => "UniqueId not found",
                ])
            );
        } elseif (strtotime(date("Y-m-d H:i:s")) >= cache::global()["dataKeys"][$tokenBrowser]["expire"]) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "message" => "Your plan as has expired. Contact support for more information.",
                ])
            );
        } elseif (empty($path)) {
            $path = "";
        }
        if (!is_dir("files")) {
            mkdir("files");
        }
        $folder = file_get_contents($path);

        // detectar se contem caracteres especiais, se tiver usa um utf8_encode
        $encoded = false;
        if (mb_detect_encoding($folder, "UTF-8", true) === false) {
            $folder = utf8_encode($folder);
            $encoded = true;
        }

        $fileEscaped = $folder;

        return $response->write(
            json_encode([
                "success" => true,
                "information" => $fileEscaped,
                "encoded" => $encoded,
            ])
        );
    }
}
