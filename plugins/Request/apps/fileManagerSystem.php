<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;

class fileManagerSystem
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        if (!security::verifyToken($request)) {
            return (bool) security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json; charset=utf-8');
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        if ($method === 'GET') {
            return self::respond($response, 200, [
                'success' => true,
                'instanceId' => getmypid(),
            ]);
        }
        if ($method !== 'POST') {
            return self::respond($response, 405, [
                'success' => false,
                'message' => 'Método não permitido.',
            ]);
        }

        $action = strtolower(trim((string) ($request->post['action'] ?? '')));
        if ($action !== 'restart') {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'Ação de sistema inválida.',
            ]);
        }

        $server = $GLOBALS['server'] ?? null;
        if (!$server instanceof Server) {
            return self::respond($response, 503, [
                'success' => false,
                'message' => 'O servidor principal não está disponível para reinício.',
            ]);
        }
        $masterPid = (int) $server->master_pid;
        if ($masterPid <= 1) {
            return self::respond($response, 503, [
                'success' => false,
                'message' => 'O processo principal do servidor não foi identificado.',
            ]);
        }

        try {
            $owner = securityPolicy::requestToken($request);
            Timer::after(750, static function () use ($server, $owner): void {
                try {
                    if (!securityPolicy::adminAllowed($owner)) return;
                    // The native supervisor starts middleware.php again after it
                    // exits. SIGKILL on manager/worker breaks graceful cleanup and
                    // may leave the old listener or PTY in an inconsistent state.
                    fileManagerRuntime::requestRestart();
                    if ($server->shutdown() === false) {
                        error_log('[filemanager] Graceful restart request was rejected by Swoole.');
                    }
                } catch (\Throwable $exception) {
                    error_log('[filemanager] Failed to request graceful restart: ' . $exception->getMessage());
                }
            });

            return self::respond($response, 202, [
                'success' => true,
                'instanceId' => getmypid(),
                'message' => 'Reinício solicitado. A conexão pode ser interrompida enquanto o supervisor reinicia o serviço.',
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 500, [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private static function respond(Response $response, int $status, array $payload): bool
    {
        $response->status($status);
        return (bool) $response->end(json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
