<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Timer;

class fileManagerSystem
{
    public static function api(Request $request, Response $response): bool
    {
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
            fileManagerRuntime::requestRestart();
            Timer::after(750, static function () use ($masterPid): void {
                $currentProcessId = getmypid();
                $processIds = fileManagerRuntime::middlewareProcessIds();
                if (!in_array($masterPid, $processIds, true)) {
                    $processIds[] = $masterPid;
                }
                rsort($processIds, SORT_NUMERIC);

                foreach ($processIds as $processId) {
                    if ($processId !== $currentProcessId) {
                        Process::kill($processId, SIGKILL);
                    }
                }

                // Este worker é encerrado por último, depois que os demais
                // deixaram de manter a porta principal aberta.
                Process::kill($currentProcessId, SIGKILL);
            });

            return self::respond($response, 202, [
                'success' => true,
                'instanceId' => getmypid(),
                'message' => 'Reinício solicitado. Os terminais e serviços auxiliares permanecerão em execução.',
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
