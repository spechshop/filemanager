<?php

namespace plugins\Request;

use Swoole\Coroutine\System;
use Swoole\Http\Request;
use Swoole\Http\Response;
class cutCopy
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::authorize($request, $response)) return true;
        $response->header("Content-Type", "application/json");
        $data = count($request->post ?? []) > 0 ? $request->post : json_decode($request->rawContent(), true);
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
        $owner = securityPolicy::requestToken($request);
        foreach ($newFiles as $old => $new) {
            securityPolicy::tree($old, $owner);
            securityPolicy::path($new, $owner, false);
            if (securityPolicy::within($new, $old)) return security::deny($response);
        }
        if ($data["type"] == "copy") {
            foreach ($newFiles as $old => $new) {
                safeFiles::copy($old, $new, $owner);
            }
        } elseif ($data["type"] == "move") {
            foreach ($newFiles as $old => $new) {
                if (!securityPolicy::validToken($owner)) return security::invalidToken($response);
                rename(securityPolicy::path($old, $owner, false), securityPolicy::path($new, $owner, false));
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
