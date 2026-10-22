<?php
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class security
{
    public static function verifyToken(Request $request): bool
    {
        return securityPolicy::validToken(securityPolicy::requestToken($request));
    }

    public static function authorize(Request $request, Response $response): bool
    {
        if (!self::verifyToken($request)) { self::invalidToken($response); return false; }
        if (!securityPolicy::originAllowed($request)) { self::deny($response); return false; }
        try { securityPolicy::preflight($request, securityPolicy::requestToken($request)); }
        catch (\DomainException $e) { self::deny($response); return false; }
        if (!self::verifyToken($request)) { self::invalidToken($response); return false; }
        return true;
    }

    public static function invalidToken(Response $response): bool
    {
        securityPolicy::log('authentication_denied');
        $response->status(401);
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => false, 'message' => 'Unauthorized']));
    }

    public static function deny(Response $response): bool
    {
        securityPolicy::log('access_denied');
        $response->status(403);
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode(['success' => false, 'message' => 'Forbidden']));
    }

    public static function sanitizeInput($input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function writeChat(array $data): bool { return false; }
}
