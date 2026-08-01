<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class fileManagerDiagnostics
{
    private const PROJECT_ROOT = __DIR__ . '/../../../';
    private const MIN_NODE_MAJOR = 22;

    public static function api(Request $request, Response $response): bool
    {
        if (!security::verifyToken($request)) {
            return (bool) security::invalidToken($response);
        }

        $response->header('Content-Type', 'application/json; charset=utf-8');
        $method = strtoupper($request->server['request_method'] ?? 'GET');
        if ($method === 'GET') {
            return self::respond($response, 200, self::snapshot());
        }
        if ($method !== 'POST') {
            return self::respond($response, 405, [
                'success' => false,
                'message' => 'Método não permitido.',
            ]);
        }

        $action = strtolower(trim((string) ($request->post['action'] ?? '')));
        if ($action !== 'install_codex') {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'Ação de diagnóstico inválida.',
            ]);
        }

        try {
            $job = self::jobStatus();
            if (($job['status'] ?? '') === 'running') {
                return self::respond($response, 409, [
                    'success' => false,
                    'message' => 'Uma instalação já está em andamento.',
                    'repair' => $job,
                ]);
            }
            self::startInstaller();
            usleep(150000);
            return self::respond($response, 202, [
                'success' => true,
                'message' => 'Instalação automática iniciada.',
                'repair' => self::jobStatus(),
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 500, [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private static function snapshot(): array
    {
        $node = self::nodeBinary();
        $nodeVersion = self::versionOutput($node);
        $nodeMajor = self::majorVersion($nodeVersion);
        $npm = self::npmCommand($node);
        $npmVersion = self::npmVersion($node, $npm);
        $codex = self::codexBinary();
        $codexVersion = self::versionOutput($codex);
        $dependencies = self::dependencyStatus();
        $tokenConfigured = self::envHasValue(self::root() . '/.env', 'CODEX_ACCESS_TOKEN');

        $checks = [
            [
                'id' => 'node',
                'name' => 'Node.js',
                'status' => $nodeMajor >= self::MIN_NODE_MAJOR ? 'ok' : 'error',
                'value' => $nodeVersion !== '' ? $nodeVersion : 'Não instalado',
                'message' => $nodeMajor >= self::MIN_NODE_MAJOR
                    ? 'Compatível com as dependências atuais (Node 22+).'
                    : 'Versão incompatível. O reparo instalará Node.js 22 isolado no File Manager.',
            ],
            [
                'id' => 'npm',
                'name' => 'npm',
                'status' => $npmVersion !== '' ? 'ok' : 'error',
                'value' => $npmVersion !== '' ? $npmVersion : 'Não disponível',
                'message' => $npmVersion !== ''
                    ? 'Gerenciador de pacotes disponível.'
                    : 'O npm será fornecido junto com o Node.js gerenciado.',
            ],
            [
                'id' => 'dependencies',
                'name' => 'Dependências Node',
                'status' => $dependencies['missing'] === [] ? 'ok' : 'error',
                'value' => $dependencies['missing'] === []
                    ? count($dependencies['installed']) . ' pacotes diretos instalados'
                    : count($dependencies['missing']) . ' pacote(s) ausente(s)',
                'message' => $dependencies['missing'] === []
                    ? 'As dependências diretas do package.json estão presentes.'
                    : 'Ausentes: ' . implode(', ', $dependencies['missing']) . '.',
            ],
            [
                'id' => 'codex',
                'name' => 'Codex CLI',
                'status' => $codexVersion !== '' ? 'ok' : 'error',
                'value' => $codexVersion !== '' ? $codexVersion : 'Não instalado',
                'message' => $codexVersion !== ''
                    ? 'CLI oficial disponível para o Codex Agent.'
                    : 'O reparo instalará o pacote oficial @openai/codex.',
            ],
            [
                'id' => 'token',
                'name' => 'Token do Codex',
                'status' => $tokenConfigured ? 'ok' : 'warning',
                'value' => $tokenConfigured ? 'Configurado' : 'Pendente',
                'message' => $tokenConfigured
                    ? 'CODEX_ACCESS_TOKEN foi encontrado sem expor seu conteúdo.'
                    : 'Defina CODEX_ACCESS_TOKEN no arquivo .env para conectar o agente.',
            ],
        ];

        $audit = self::auditSummary();
        if ($audit !== null) {
            $total = (int) ($audit['total'] ?? (
                ($audit['info'] ?? 0) + ($audit['low'] ?? 0) + ($audit['moderate'] ?? 0)
                + ($audit['high'] ?? 0) + ($audit['critical'] ?? 0)
            ));
            $checks[] = [
                'id' => 'audit',
                'name' => 'npm audit',
                'status' => ($audit['high'] ?? 0) + ($audit['critical'] ?? 0) > 0
                    ? 'warning'
                    : ($total > 0 ? 'warning' : 'ok'),
                'value' => $total === 0 ? 'Nenhuma vulnerabilidade' : "$total vulnerabilidade(s)",
                'message' => sprintf(
                    'Baixa: %d · Moderada: %d · Alta: %d · Crítica: %d',
                    $audit['low'] ?? 0,
                    $audit['moderate'] ?? 0,
                    $audit['high'] ?? 0,
                    $audit['critical'] ?? 0
                ),
            ];
        }

        return [
            'success' => true,
            'healthy' => !array_filter(
                $checks,
                static fn(array $check): bool => $check['status'] === 'error'
            ),
            'hasWarnings' => (bool) array_filter(
                $checks,
                static fn(array $check): bool => $check['status'] === 'warning'
            ),
            'checks' => $checks,
            'repair' => self::jobStatus(),
        ];
    }

    private static function startInstaller(): void
    {
        $root = self::root();
        $script = $root . '/scripts/install-codex.sh';
        if (!is_file($script) || !is_readable($script)) {
            throw new \RuntimeException('O instalador scripts/install-codex.sh não está disponível.');
        }
        $runtime = $root . '/.runtime';
        if (!is_dir($runtime) && !mkdir($runtime, 0750, true) && !is_dir($runtime)) {
            throw new \RuntimeException('Não foi possível criar o diretório .runtime.');
        }
        $log = $runtime . '/codex-installer.log';
        $command = 'nohup bash ' . escapeshellarg($script) . ' ' . escapeshellarg($root)
            . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $pid = trim((string) shell_exec($command));
        if (!ctype_digit($pid) || (int) $pid <= 1) {
            throw new \RuntimeException('Não foi possível iniciar o instalador em segundo plano.');
        }
    }

    private static function jobStatus(): array
    {
        $runtime = self::root() . '/.runtime';
        $stateFile = $runtime . '/codex-installer.json';
        $state = [];
        if (is_file($stateFile)) {
            $decoded = json_decode((string) @file_get_contents($stateFile), true);
            $state = is_array($decoded) ? $decoded : [];
        }
        $pid = (int) ($state['pid'] ?? 0);
        if (($state['status'] ?? '') === 'running' && !self::installerProcessRunning($pid)) {
            $state['status'] = 'failed';
            $state['message'] = 'O instalador foi encerrado antes de concluir. Consulte o log.';
        }
        $log = '';
        $logFile = $runtime . '/codex-installer.log';
        if (is_file($logFile) && is_readable($logFile)) {
            $size = (int) filesize($logFile);
            $handle = @fopen($logFile, 'rb');
            if (is_resource($handle)) {
                if ($size > 16000) {
                    fseek($handle, -16000, SEEK_END);
                }
                $log = (string) stream_get_contents($handle);
                fclose($handle);
                if ($size > 16000) {
                    $log = "…\n" . substr($log, (int) strpos($log, "\n") + 1);
                }
            }
        }

        return [
            'status' => $state['status'] ?? 'idle',
            'message' => $state['message'] ?? 'Nenhum reparo executado nesta instalação.',
            'updatedAt' => $state['updatedAt'] ?? null,
            'exitCode' => $state['exitCode'] ?? null,
            'log' => $log,
        ];
    }

    private static function installerProcessRunning(int $pid): bool
    {
        if ($pid <= 1 || !is_dir("/proc/$pid")) {
            return false;
        }
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        return is_string($cmdline)
            && str_contains(str_replace("\0", ' ', $cmdline), '/scripts/install-codex.sh');
    }

    private static function dependencyStatus(): array
    {
        $packageFile = self::root() . '/package.json';
        $package = json_decode((string) @file_get_contents($packageFile), true);
        $dependencies = is_array($package['dependencies'] ?? null)
            ? array_keys($package['dependencies'])
            : [];
        $installed = [];
        $missing = [];
        foreach ($dependencies as $dependency) {
            $manifest = self::root() . '/node_modules/' . $dependency . '/package.json';
            if (is_file($manifest)) {
                $installed[] = $dependency;
            } else {
                $missing[] = $dependency;
            }
        }
        return ['installed' => $installed, 'missing' => $missing];
    }

    private static function auditSummary(): ?array
    {
        $file = self::root() . '/.runtime/npm-audit.json';
        $audit = json_decode((string) @file_get_contents($file), true);
        $vulnerabilities = $audit['metadata']['vulnerabilities'] ?? null;
        return is_array($vulnerabilities) ? $vulnerabilities : null;
    }

    private static function nodeBinary(): ?string
    {
        $managed = self::root() . '/.runtime/node/bin/node';
        if (is_file($managed) && is_executable($managed)
            && self::majorVersion(self::versionOutput($managed)) >= self::MIN_NODE_MAJOR) {
            return $managed;
        }
        return self::commandPath('node');
    }

    private static function npmCommand(?string $node): ?string
    {
        if ($node !== null) {
            $cli = dirname($node) . '/../lib/node_modules/npm/bin/npm-cli.js';
            if (is_file($cli)) {
                return (string) realpath($cli);
            }
        }
        return self::commandPath('npm');
    }

    private static function npmVersion(?string $node, ?string $npm): string
    {
        if ($npm === null) {
            return '';
        }
        if (str_ends_with($npm, '.js') && $node !== null) {
            return trim((string) shell_exec(
                escapeshellarg($node) . ' ' . escapeshellarg($npm) . ' --version 2>/dev/null'
            ));
        }
        return self::versionOutput($npm);
    }

    private static function codexBinary(): ?string
    {
        $candidates = [
            self::root() . '/.runtime/codex/bin/codex',
            self::root() . '/.runtime/node/bin/codex',
            self::commandPath('codex'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function commandPath(string $command): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        return $path !== '' ? $path : null;
    }

    private static function versionOutput(?string $binary): string
    {
        if ($binary === null) {
            return '';
        }
        $path = self::root() . '/.runtime/node/bin' . PATH_SEPARATOR . (string) getenv('PATH');
        return trim((string) shell_exec(
            'PATH=' . escapeshellarg($path) . ' ' . escapeshellarg($binary) . ' --version 2>/dev/null'
        ));
    }

    private static function majorVersion(string $version): int
    {
        return preg_match('/(?:^|\s)v?(\d+)\./', $version, $matches) === 1 ? (int) $matches[1] : 0;
    }

    private static function envHasValue(string $file, string $name): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return false;
        }
        $contents = (string) @file_get_contents($file);
        return preg_match(
            '/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*(?!["\']?\s*["\']?\s*$).+$/m',
            $contents
        ) === 1;
    }

    private static function root(): string
    {
        return rtrim((string) realpath(self::PROJECT_ROOT), '/');
    }

    private static function respond(Response $response, int $status, array $payload): bool
    {
        $response->status($status);
        return $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
