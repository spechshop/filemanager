<?php

namespace plugins\Request;

use plugins\Start\cache;

class checkToken
{
    public static function api($request, $response)
    {
        $_GET = $request->get;
        $_POST = $request->post;
        $response->header('Content-Type', 'application/json');
        if (!empty($_GET['tokenBrowser'])) $tokenBrowser = $_GET['tokenBrowser'];
        if (!empty($_POST['tokenBrowser'])) $tokenBrowser = $_POST['tokenBrowser'];
        if (empty($tokenBrowser)) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Identifier not found'
            ]));
        } elseif (!key_exists($tokenBrowser, cache::global()['dataKeys'])) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'UniqueId not found',
            ]));
        } elseif (strtotime(date('Y-m-d H:i:s')) >= cache::global()['dataKeys'][$tokenBrowser]['expire']) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Your plan as has expired. Contact support for more information.',
            ]));
        }
        return $response->end(json_encode([
            'success' => true,
            'message' => sprintf("Olá %s", cache::global()['dataKeys'][$tokenBrowser]['nameClient']),
        ]));
    }
}