<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class newFile
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::authorize($request, $response)) return true;
        $data = ($request->post ?: json_decode($request->rawContent(), true)) ?: [];
        $token = securityPolicy::requestToken($request);
        $path = securityPolicy::path(($data['path'] ?? '') . '/' . securityPolicy::filename($data['name'] ?? null), $token, false);
        $ok = match ($data['type'] ?? '') {
            'file' => file_put_contents($path, $data['content'] ?? '', LOCK_EX) !== false,
            'dir' => !file_exists($path) && mkdir($path, 0750),
            default => false,
        };
        return $response->end(json_encode(['success' => $ok, 'information' => $ok ? 'ok' : 'Operation failed']));
    }
}
