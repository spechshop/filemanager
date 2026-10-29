<?php
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

require_once __DIR__ . '/../components/privateJson.php';

class deleteTerminal
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $tokenBrowser = securityPolicy::requestToken($request);
        $terminalId = $request->get['id'] ?? null;

        if (!$tokenBrowser || !$terminalId) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => false, 'error' => 'Missing parameters']));
        }

        $nameFile = securityPolicy::dataPath('sessions-terminals.json', 'sessions-terminals.json');
        privateJson::update($nameFile, static function (array $records) use ($tokenBrowser, $terminalId): array {
            if (!securityPolicy::terminalAllowed($tokenBrowser)) throw new \DomainException('revoked');
            $records[$tokenBrowser] = array_values(array_filter($records[$tokenBrowser] ?? [],
                static fn($session) => (is_string($session) ? $session : ($session['id'] ?? null)) !== $terminalId));
            return $records;
        });
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => true]));
    }
}
