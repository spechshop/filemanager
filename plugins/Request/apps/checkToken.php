<?php
namespace plugins\Request;
class checkToken
{
    public static function api($request, $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $record = securityPolicy::tokens()[securityPolicy::requestToken($request)];
        return $response->end(json_encode(['success' => true, 'message' => 'Olá ' . ($record['nameClient'] ?? '')]));
    }
}
