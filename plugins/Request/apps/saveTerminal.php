<?php
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class saveTerminal
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);

        $body = json_decode($request->rawContent(), true);
        // token comes as query param (verifyToken already validated it)
        $tokenBrowser = $request->get['tokenBrowser'] ?? null;
        $terminalId = $body['id'] ?? null;
        $terminalName = $body['name'] ?? 'Terminal';

        if (!$tokenBrowser || !$terminalId) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => false, 'error' => 'Missing parameters']));
        }

        $nameFile = 'sessions-terminals.json';
        if (!file_exists($nameFile)) {
            file_put_contents($nameFile, json_encode([$tokenBrowser => []]));
        }

        $terminals = json_decode(file_get_contents($nameFile), true);
        if (!isset($terminals[$tokenBrowser])) {
            $terminals[$tokenBrowser] = [];
        }

        $found = false;
        foreach ($terminals[$tokenBrowser] as &$session) {
            if ($session['id'] === $terminalId) {
                $session['name'] = $terminalName;
                $found = true;
                break;
            }
        }
        unset($session);

        if (!$found) {
            $terminals[$tokenBrowser][] = [
                'id' => $terminalId,
                'name' => $terminalName,
                'createdAt' => date('c'),
            ];
        }

        file_put_contents($nameFile, json_encode($terminals, JSON_PRETTY_PRINT));

        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => true]));
    }
}
