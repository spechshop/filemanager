<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class fileManagerSettings
{
    public static function api(Request $request, Response $response): bool
    {
        if (!security::verifyToken($request)) {
            return (bool) security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json; charset=utf-8');
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        $token = trim((string) ($request->get['tokenBrowser'] ?? $request->post['tokenBrowser'] ?? ''));

        if ($method === 'GET') {
            try {
                return self::respond($response, 200, [
                    'success' => true,
                    'settings' => self::publicSettings(fileManagerConfig::read(), $token),
                ]);
            } catch (\Throwable $exception) {
                return self::respond($response, 500, [
                    'success' => false,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($method !== 'POST') {
            return self::respond($response, 405, [
                'success' => false,
                'message' => 'Método não permitido.',
            ]);
        }

        $group = strtolower(trim((string) ($request->post['settingGroup'] ?? 'system')));
        if ($group === 'codex') {
            return self::saveCodexSettings($request, $response, $token);
        }
        if ($group !== 'system') {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'Grupo de configurações inválido.',
            ]);
        }

        $autoRestart = self::parseBoolean($request->post['autoRestart'] ?? null);
        if ($autoRestart === null) {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'O valor de autoRestart deve ser verdadeiro ou falso.',
            ]);
        }

        try {
            $config = fileManagerConfig::update(static function (array $config) use ($autoRestart): array {
                $config['fileManager'] = is_array($config['fileManager'] ?? null)
                    ? $config['fileManager']
                    : [];
                $config['fileManager']['autoRestart'] = $autoRestart;
                return $config;
            });

            return self::respond($response, 200, [
                'success' => true,
                'message' => 'Configurações salvas com sucesso.',
                'settings' => self::publicSettings($config, $token),
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 500, [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private static function saveCodexSettings(Request $request, Response $response, string $token): bool
    {
        $model = trim((string) ($request->post['codexModel'] ?? ''));
        $reasoningEffort = strtolower(trim((string) ($request->post['codexReasoningEffort'] ?? '')));
        if ($model !== '' && !preg_match('/^[a-z0-9][a-z0-9._:+-]{0,127}$/i', $model)) {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'O modelo selecionado é inválido.',
            ]);
        }
        if ($reasoningEffort !== '' && !preg_match('/^[a-z][a-z0-9_-]{0,31}$/i', $reasoningEffort)) {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'O nível de raciocínio selecionado é inválido.',
            ]);
        }
        if ($reasoningEffort !== '' && $model === '') {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'Selecione um modelo antes de definir o raciocínio.',
            ]);
        }

        try {
            $config = fileManagerConfig::setCodexPreferences(
                $token,
                $model !== '' ? $model : null,
                $reasoningEffort !== '' ? $reasoningEffort : null
            );
            return self::respond($response, 200, [
                'success' => true,
                'message' => 'Preferências do Codex salvas somente para o seu token.',
                'settings' => self::publicSettings($config, $token),
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 500, [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private static function publicSettings(array $config, string $token): array
    {
        return [
            'autoRestart' => ($config['fileManager']['autoRestart'] ?? true) !== false,
            'codex' => fileManagerConfig::codexPreferences($config, $token),
        ];
    }

    private static function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return null;
    }

    private static function respond(Response $response, int $status, array $payload): bool
    {
        $response->status($status);
        return $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
