<?php
declare(strict_types=1);
namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

/** Capability-only endpoint, called only by the explicit router entries. */
final class fileManagerTunnelAgent
{
    public static function api(Request $request, Response $response, string $bootstrapSecret = ''): bool
    {
        $response->header('Cache-Control', 'no-store');
        $response->header('Content-Type', 'application/json; charset=utf-8');
        if (!securityPolicy::originAllowed($request)) return security::deny($response);
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        if (!in_array($method, ['GET', 'POST'], true)) { $response->status(405); return $response->end(); }
        if ($bootstrapSecret !== '' && $method !== 'GET') { $response->status(405); return $response->end(); }
        if (strlen($request->rawContent()) > 2048 || (int) ($request->header['content-length'] ?? 0) > 2048) {
            $response->status(413); return $response->end();
        }
        $authorization = $request->header['authorization'] ?? '';
        $secret = is_string($authorization) && preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $match) ? $match[1] : '';
        if ($bootstrapSecret !== '') {
            if ($authorization !== '' && !hash_equals($bootstrapSecret, $secret)) return security::deny($response);
            $secret = $bootstrapSecret;
        }
        if ($secret === '') { $response->status(401); return $response->end(json_encode(['success' => false])); }
        try {
            $report = null;
            if ($method === 'POST') {
                $report = json_decode($request->rawContent(), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($report)) throw new \InvalidArgumentException('Invalid report');
            }
            $result = tunnelManager::agent($secret, $report);
            if ($method === 'GET') {
                $response->header('Content-Type', 'text/x-shellscript; charset=utf-8');
                return $response->end(tunnelManager::script($result));
            }
            return $response->end(json_encode($result));
        } catch (\DomainException $error) {
            $response->status(403);
        } catch (\UnexpectedValueException $error) {
            $response->status(409);
        } catch (\InvalidArgumentException | \JsonException $error) {
            $response->status(422);
        } catch (\Throwable $error) {
            $response->status(500);
        }
        return $response->end(json_encode(['success' => false, 'message' => 'Conexão de túnel indisponível. Gere um novo comando no painel.'], JSON_UNESCAPED_UNICODE));
    }
}
