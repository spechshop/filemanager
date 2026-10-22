<?php
namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

class getTerminals
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response)) return true;

        $tokenBrowser = securityPolicy::requestToken($request);
        $response->header('Content-Type', 'application/json');
        $nameFile = 'sessions-terminals.json';
        if (is_file($nameFile)) chmod($nameFile, 0600);
        if (!file_exists($nameFile)) {
            file_put_contents($nameFile, json_encode([
                $tokenBrowser => []
            ]));
        }
        $terminals = json_decode(file_get_contents($nameFile), true);
        if (!key_exists($tokenBrowser, $terminals)) {
            $terminals[$tokenBrowser] = [];
            file_put_contents($nameFile, json_encode($terminals, JSON_PRETTY_PRINT));
        }
        chmod($nameFile, 0600);
        $sessions = $terminals[$tokenBrowser];
        // Migrate legacy flat-array format (just IDs) to object format
        $sessions = array_map(function ($item) {
            if (is_string($item)) {
                return ['id' => $item, 'name' => 'Terminal', 'createdAt' => date('c')];
            }
            return $item;
        }, $sessions);
        $terminals = $sessions;



        return $response->end(json_encode($terminals, JSON_PRETTY_PRINT));

    }
}
