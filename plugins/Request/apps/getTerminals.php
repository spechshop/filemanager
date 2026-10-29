<?php
namespace plugins\Request;

use plugins\Extension\utilsFunction;
use Swoole\Http\Request;
use Swoole\Http\Response;

require_once __DIR__ . '/../components/privateJson.php';

class getTerminals
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $tokenBrowser = securityPolicy::requestToken($request);
        $response->header('Content-Type', 'application/json');
        $nameFile = securityPolicy::dataPath('sessions-terminals.json', 'sessions-terminals.json');
        $records = privateJson::read($nameFile);
        $terminals = array_map(static fn($item) => is_string($item)
            ? ['id' => $item, 'name' => 'Terminal', 'createdAt' => date('c')] : $item, $records[$tokenBrowser] ?? []);
        return $response->end(json_encode($terminals, JSON_PRETTY_PRINT));

    }
}
