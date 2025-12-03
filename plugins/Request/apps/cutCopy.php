<?php

namespace plugins\Request;

use Swoole\Coroutine\System;
use Swoole\Http\Request;
use Swoole\Http\Response;
class cutCopy
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }
        $response->header("Content-Type", "application/json");
        $data = count($request->post) > 0 ? $request->post : json_decode($request->rawContent(), true);
        $accept = ["move", "copy"];
        if (!in_array($data["type"], $accept)) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "information" => "Invalid type",
                ])
            );
        }
        $newFiles = [];
        $files = $data["files"];
        $moveTo = $request->get["dest"];
        for ($i = 0; $i < count($files); $i++) {
            $files[$i] = $files[$i];
        }
        for ($i = 0; $i < count($files); $i++) {
            $file = $files[$i];
            if (!file_exists($file)) {
                return $response->end(
                    json_encode([
                        "success" => false,
                        "information" => "File not found",
                    ])
                );
            }
            $oldKey = $file;
            $split = explode("/", $file);
            $file = $split[count($split) - 1];
            $newFiles[$oldKey] = $moveTo . "/" . $file;
        }
        if ($data["type"] == "copy") {
            foreach ($newFiles as $old => $new) {
                System::exec(sprintf("cp -r %s %s", escapeshellarg($old), escapeshellarg($new)));
            }
        } elseif ($data["type"] == "move") {
            foreach ($newFiles as $old => $new) {
                System::exec(sprintf("mv %s %s", escapeshellarg($old), escapeshellarg($new)));
            }
        }
        return $response->end(
            json_encode([
                "success" => true,
                "information" => "ok",
            ])
        );
    }
}
