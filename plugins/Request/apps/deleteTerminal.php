<?php
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class deleteTerminal
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) return security::invalidToken($response);

        $tokenBrowser = $request->get['tokenBrowser'] ?? null;
        $terminalId = $request->get['id'] ?? null;

        if (!$tokenBrowser || !$terminalId) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => false, 'error' => 'Missing parameters']));
        }

        $nameFile = 'sessions-terminals.json';
        if (!file_exists($nameFile)) {
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => true]));
        }

        $terminals = json_decode(file_get_contents($nameFile), true);
        if (isset($terminals[$tokenBrowser])) {
            $terminals[$tokenBrowser] = array_values(
                array_filter($terminals[$tokenBrowser], fn($s) => $s['id'] !== $terminalId)
            );
            file_put_contents($nameFile, json_encode($terminals, JSON_PRETTY_PRINT));
        }

        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => true]));
    }
}
