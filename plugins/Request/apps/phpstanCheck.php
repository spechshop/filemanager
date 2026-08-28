<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Endpoint de análise estática (PHPStan) sob demanda para o editor.
 *
 * Recebe o conteúdo atual do arquivo (ou o caminho de um arquivo real) e
 * roda o phpstan (vendor/bin/phpstan) devolvendo os diagnósticos em JSON,
 * prontos para virarem marcadores (markers) no Monaco:
 *   { line, message, severity, source }
 *
 * É a contraparte no backend das melhorias do editor: em vez de só sugerir,
 * o servidor passa a "entender" o código com as abordagens recomendadas.
 */
class phpstanCheck
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json');
        $data = json_decode($request->rawContent(), true) ?: [];

        $code     = $data['code'] ?? null;
        $nameFile = $data['nameFile'] ?? '';
        $level    = isset($data['level']) ? (int) $data['level'] : 5;
        $level    = max(0, min(9, $level));

        $baseDir = appController::baseDir();
        $phpstan = $baseDir . 'vendor/bin/phpstan';

        if (!is_file($phpstan)) {
            return $response->end(json_encode([
                'success'     => false,
                'available'   => false,
                'message'     => 'phpstan não encontrado em vendor/bin/phpstan',
                'diagnostics' => [],
            ]));
        }

        // Alvo: arquivo real quando existir; senão grava o código num temporário.
        $tmpFile = null;
        if ($nameFile !== '' && is_file($nameFile)) {
            $target = $nameFile;
        } else {
            if ($code === null) {
                return $response->end(json_encode([
                    'success'     => false,
                    'message'     => 'É necessário enviar "code" ou "nameFile".',
                    'diagnostics' => [],
                ]));
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'pstan_') . '.php';
            file_put_contents($tmpFile, $code);
            $target = $tmpFile;
        }

        // Config mínima temporária (inclui regras do projeto, mas isola o alvo).
        $tmpConf  = tempnam(sys_get_temp_dir(), 'pstan_conf_') . '.neon';
        $autoload = $baseDir . 'vendor/autoload.php';
        $neonBase = $baseDir . 'phpstan.neon';

        $conf = "";
        if (is_file($neonBase)) {
            $conf .= "includes:\n    - " . $neonBase . "\n";
        }
        $conf    .= "parameters:\n";
        $conf    .= "    level: {$level}\n";
        $conf    .= "    reportUnmatchedIgnoredErrors: false\n";
        if (is_file($autoload)) {
            $conf .= "    bootstrapFiles:\n        - " . $autoload . "\n";
        }
        file_put_contents($tmpConf, $conf);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpstan) . ' analyse'
            . ' --no-progress --error-format=json'
            . ' --configuration=' . escapeshellarg($tmpConf)
            . ' ' . escapeshellarg($target) . ' 2>/dev/null';

        $raw = shell_exec($cmd);

        if ($tmpFile !== null) {
            @unlink($tmpFile);
        }
        @unlink($tmpConf);

        $parsed      = json_decode((string) $raw, true);
        $diagnostics = [];
        if (is_array($parsed) && !empty($parsed['files'])) {
            foreach ($parsed['files'] as $fileData) {
                foreach (($fileData['messages'] ?? []) as $msg) {
                    $diagnostics[] = [
                        'line'     => max(1, (int) ($msg['line'] ?? 1)),
                        'message'  => (string) ($msg['message'] ?? ''),
                        'severity' => 'error',
                        'source'   => 'phpstan',
                    ];
                }
            }
        }

        return $response->end(json_encode([
            'success'     => true,
            'available'   => true,
            'level'       => $level,
            'diagnostics' => $diagnostics,
        ]));
    }
}
