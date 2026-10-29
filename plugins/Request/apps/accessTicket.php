<?php
declare(strict_types=1);
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
final class accessTicket
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        if (($request->server['request_method'] ?? '') !== 'POST') { $response->status(405); return $response->end(); }
        $data = json_decode($request->rawContent(), true) ?: [];
        $owner = securityPolicy::requestToken($request);
        if (($data['kind'] ?? '') === 'websocket') {
            $socket = $data['socket'] ?? '';
            if (!securityPolicy::ownsSocket($owner, $socket)) return security::deny($response);
            $scope = ['kind' => 'websocket', 'socket' => $socket];
        } else {
            $operation = $data['operation'] ?? 'imageBase64';
            if (!in_array($operation, ['imageBase64', 'downloadFile', 'getPreview'], true)) return security::deny($response);
            securityPolicy::path($data['path'] ?? '', $owner);
            $scope = ['kind' => 'media', 'operation' => $operation, 'path' => $data['path']];
        }
        $response->header('Cache-Control', 'no-store');
        return $response->end(json_encode(['ticket' => securityPolicy::issueTicket($owner, $scope)]));
    }
}
