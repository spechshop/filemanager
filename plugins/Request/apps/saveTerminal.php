<?php
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

require_once __DIR__ . '/../components/privateJson.php';

class saveTerminal
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $body = json_decode($request->rawContent(), true);
        // token comes as query param (verifyToken already validated it)
        $tokenBrowser = securityPolicy::requestToken($request);
        $terminalId = $body['id'] ?? null;
        $terminalName = $body['name'] ?? 'Terminal';

        if (!$tokenBrowser || !$terminalId) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => false, 'error' => 'Missing parameters']));
        }

        if (!is_string($terminalName) || strlen($terminalName) > 256) return security::deny($response);
        $nameFile = securityPolicy::dataPath('sessions-terminals.json', 'sessions-terminals.json');
        privateJson::update($nameFile, static function (array $terminals) use ($tokenBrowser, $terminalId, $terminalName): array {
            if (!securityPolicy::terminalAllowed($tokenBrowser)) throw new \DomainException('revoked');
            $sessions = $terminals[$tokenBrowser] ?? [];
            $found = false;
            foreach ($sessions as &$session) {
                if (is_string($session)) $session = ['id' => $session, 'name' => 'Terminal'];
                if (($session['id'] ?? null) === $terminalId) { $session['name'] = $terminalName; $found = true; }
            }
            unset($session);
            if (!$found) $sessions[] = ['id' => $terminalId, 'name' => $terminalName, 'createdAt' => date('c')];
            $terminals[$tokenBrowser] = $sessions;
            return $terminals;
        });
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => true]));
    }
}
