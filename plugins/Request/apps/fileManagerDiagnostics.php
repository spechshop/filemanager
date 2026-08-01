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

        $nodeTarget = strtolower(trim((string) ($request->post['nodeVersion'] ?? 'preserve')));
        if (!in_array($nodeTarget, ['preserve', '22', '24', '26'], true)) {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'A versão escolhida do Node.js é inválida.',
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
            self::startInstaller($nodeTarget);
            usleep(150000);
            return self::respond($response, 202, [
                'success' => true,
                'message' => $nodeTarget === 'preserve'
                    ? 'Reparo automático iniciado, mantendo o runtime Node.js compatível.'
                    : "Atualização para Node.js $nodeTarget e reparo iniciados.",
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
        $managedNode = self::managedNodeBinary();
        $managedNodeVersion = self::versionOutput($managedNode);
        $systemNode = self::commandPath('node');
        $systemNodeVersion = self::versionOutput($systemNode);
        $ptyNode = self::ptyNodeBinary();
        $phpPtyAvailable = is_file(self::root() . '/pty.php') && extension_loaded('swoole');
        $ptyBackend = self::ptyBackend();
        $ptyAvailable = match ($ptyBackend) {
            'node' => $ptyNode !== null,
            'php' => $phpPtyAvailable,
            default => $ptyNode !== null || $phpPtyAvailable,
        };
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
                    ? 'Compatível (Node 22+). Em uso: ' . ($node ?? 'não identificado') . '.'
                    : 'Versão incompatível. O reparo pode instalar um Node.js 22+ isolado no File Manager.',
            ],
            [
                'id' => 'system_node',
                'name' => 'Node.js do sistema',
                'status' => self::majorVersion($systemNodeVersion) >= self::MIN_NODE_MAJOR ? 'ok' : 'warning',
                'value' => $systemNodeVersion !== '' ? $systemNodeVersion : 'Não encontrado',
                'message' => $systemNode !== null
                    ? 'Executável: ' . $systemNode . '.'
                    : 'Nenhum comando node foi encontrado no PATH do servidor.',
            ],
            [
                'id' => 'managed_node',
                'name' => 'Node.js gerenciado',
                'status' => self::majorVersion($managedNodeVersion) >= self::MIN_NODE_MAJOR ? 'ok' : 'warning',
                'value' => $managedNodeVersion !== '' ? $managedNodeVersion : 'Não instalado',
                'message' => $managedNode !== null
                    ? 'Runtime isolado em .runtime/node; pode ser atualizado abaixo.'
                    : 'Opcional quando o Node.js do sistema já é compatível.',
            ],
            [
                'id' => 'node_pty',
                'name' => 'PTY via Node.js',
                'status' => $ptyNode !== null ? 'ok' : ($ptyBackend === 'node' ? 'error' : 'warning'),
                'value' => $ptyNode !== null ? 'Disponível' : 'Indisponível',
                'message' => $ptyNode !== null
                    ? 'node-pty carregado com ' . $ptyNode . '.'
                    : 'Nenhum runtime Node.js disponível conseguiu carregar o módulo nativo node-pty.',
            ],
            [
                'id' => 'php_pty',
                'name' => 'PTY via PHP',
                'status' => $phpPtyAvailable ? 'ok' : ($ptyBackend === 'php' ? 'error' : 'warning'),
                'value' => $phpPtyAvailable ? 'Disponível' : 'Indisponível',
                'message' => $phpPtyAvailable
                    ? 'pty.php e a extensão Swoole estão disponíveis.'
                    : 'pty.php ou a extensão Swoole não está disponível.',
            ],
            [
                'id' => 'pty_backend',
                'name' => 'Backend PTY selecionado',
                'status' => $ptyAvailable ? 'ok' : 'error',
                'value' => match ($ptyBackend) {
                    'node' => 'Node.js',
                    'php' => 'PHP/Swoole',
                    default => 'Automático',
                },
                'message' => $ptyAvailable
                    ? 'A preferência salva pode ser iniciada pelo gerenciador de serviços.'
                    : 'O backend escolhido não está disponível neste ambiente.',
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
            'nodeVersions' => [
                ['value' => 'preserve', 'label' => 'Manter runtime compatível'],
                ['value' => '22', 'label' => 'Node.js 22 LTS (manutenção)'],
                ['value' => '24', 'label' => 'Node.js 24 LTS (recomendado)'],
                ['value' => '26', 'label' => 'Node.js 26 Current'],
            ],
            'repair' => self::jobStatus(),
        ];
    }

    private static function startInstaller(string $nodeTarget): void
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
            . ' ' . escapeshellarg($nodeTarget)
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

    private static function ptyBackend(): string
    {
        try {
            return fileManagerConfig::ptyBackend(fileManagerConfig::read());
        } catch (\Throwable) {
            return 'auto';
        }
    }

    private static function managedNodeBinary(): ?string
    {
        $managed = self::root() . '/.runtime/node/bin/node';
        return is_file($managed) && is_executable($managed) ? $managed : null;
    }

    private static function ptyNodeBinary(): ?string
    {
        if (!is_file(self::root() . '/pty.js') || !is_dir(self::root() . '/node_modules/node-pty')) {
            return null;
        }
        $candidates = array_values(array_unique(array_filter([
            self::managedNodeBinary(),
            self::commandPath('node'),
        ])));
        foreach ($candidates as $candidate) {
            if (self::nodeCanRequire($candidate, 'node-pty')) {
                return $candidate;
            }
        }
        return null;
    }

    private static function nodeCanRequire(string $node, string $module): bool
    {
        $command = 'cd ' . escapeshellarg(self::root()) . ' && '
            . escapeshellarg($node) . ' -e '
            . escapeshellarg('require(' . json_encode($module) . ')')
            . ' >/dev/null 2>&1';
        exec($command, $output, $exitCode);
        return $exitCode === 0;
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
