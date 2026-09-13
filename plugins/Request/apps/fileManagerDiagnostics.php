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
            if (in_array(($job['status'] ?? ''), ['starting', 'running'], true)) {
                return self::respond($response, 409, [
                    'success' => false,
                    'message' => 'Uma instalação já está em andamento.',
                    'repair' => $job,
                ]);
            }
            self::startInstaller($nodeTarget);
            usleep(150000);
            $repair = self::jobStatus();
            if (($repair['status'] ?? '') === 'failed') {
                return self::respond($response, 500, [
                    'success' => false,
                    'message' => $repair['message'] ?? 'O instalador não conseguiu iniciar.',
                    'repair' => $repair,
                ]);
            }
            return self::respond($response, 202, [
                'success' => true,
                'message' => $nodeTarget === 'preserve'
                    ? 'Reparo automático iniciado, mantendo o runtime Node.js compatível.'
                    : "Atualização para Node.js $nodeTarget e reparo iniciados.",
                'repair' => $repair,
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 500, [
                'success' => false,
                'message' => $exception->getMessage(),
                'repair' => self::jobStatus(),
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
        $localLoginActive = self::codexLoginActive($codex);

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
                'name' => 'Autenticação do Codex',
                'status' => ($localLoginActive || $tokenConfigured) ? 'ok' : 'warning',
                'value' => $localLoginActive ? 'Sessão ChatGPT local' : ($tokenConfigured ? 'Token configurado' : 'Pendente'),
                'message' => $localLoginActive
                    ? 'Uma sessão criada por codex login está disponível para o serviço.'
                    : ($tokenConfigured
                        ? 'CODEX_ACCESS_TOKEN foi encontrado sem expor seu conteúdo.'
                        : 'Execute codex login como o usuário do serviço ou configure CODEX_ACCESS_TOKEN.'),
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
        $files = self::jobFiles();
        $runtime = $root . '/.runtime';
        $startedAt = gmdate('c');

        self::replaceLog($files['log'], '[FileManager] Preparando o instalador em ' . $startedAt . ".\n");
        self::writeJobState($files['state'], [
            'status' => 'starting',
            'message' => 'Preparando o instalador...',
            'pid' => 0,
            'updatedAt' => $startedAt,
            'exitCode' => null,
        ]);

        $launcherExitCode = null;
        try {
            if (!is_file($script) || !is_readable($script)) {
                throw new \RuntimeException('O instalador scripts/install-codex.sh não está disponível.');
            }
            if (!is_dir($runtime) && !mkdir($runtime, 0750, true) && !is_dir($runtime)) {
                throw new \RuntimeException('Não foi possível criar o diretório .runtime.');
            }
            if (!is_writable($runtime)) {
                self::appendLog(
                    $files['log'],
                    '[FileManager][aviso] .runtime não permite escrita para o usuário do File Manager; '
                    . "o instalador registrará abaixo o ponto exato da falha.\n"
                );
            }

            $command = 'nohup bash ' . escapeshellarg($script) . ' ' . escapeshellarg($root)
                . ' ' . escapeshellarg($nodeTarget)
                . ' >> ' . escapeshellarg($files['log']) . ' 2>&1 < /dev/null & printf "%s\\n" "$!"';
            self::appendLog($files['log'], self::launchDiagnostics($command));

            $output = [];
            $warnings = [];
            $exitCode = -1;
            $launchException = null;
            set_error_handler(
                static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
                    $warnings[] = sprintf('%s em %s:%d', $message, $file, $line);
                    return true;
                }
            );
            try {
                exec($command, $output, $exitCode);
            } catch (\Throwable $exception) {
                $launchException = $exception;
            } finally {
                restore_error_handler();
            }
            $launcherExitCode = $exitCode;

            if ($output !== []) {
                self::appendLog(
                    $files['log'],
                    "[FileManager][launcher][stdout]\n" . implode("\n", $output) . "\n"
                );
            }
            foreach ($warnings as $warning) {
                self::appendLog($files['log'], '[FileManager][launcher][aviso] ' . $warning . "\n");
            }
            self::appendLog($files['log'], '[FileManager][launcher] Código de saída: ' . $exitCode . ".\n");

            if ($launchException instanceof \Throwable) {
                throw new \RuntimeException(
                    'Falha ao executar o lançador: ' . $launchException->getMessage(),
                    0,
                    $launchException
                );
            }
            $pid = trim((string) end($output));
            if ($exitCode !== 0 || !ctype_digit($pid) || (int) $pid <= 1) {
                $details = $warnings !== [] ? end($warnings) : 'nenhum PID foi devolvido pelo sistema';
                throw new \RuntimeException('Não foi possível criar o processo do instalador: ' . $details . '.');
            }
        } catch (\Throwable $exception) {
            self::appendLog($files['log'], '[FileManager][erro] ' . $exception->getMessage() . "\n");
            self::writeJobState($files['state'], [
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'pid' => 0,
                'updatedAt' => gmdate('c'),
                'exitCode' => $launcherExitCode,
            ]);
            throw $exception;
        }
    }

    private static function jobStatus(): array
    {
        $files = self::jobFiles();
        $stateFile = $files['state'];
        $state = [];
        if (is_file($stateFile)) {
            $decoded = json_decode((string) @file_get_contents($stateFile), true);
            $state = is_array($decoded) ? $decoded : [];
        }
        $pid = (int) ($state['pid'] ?? 0);
        if (($state['status'] ?? '') === 'running' && !self::installerProcessRunning($pid)) {
            $state['status'] = 'failed';
            $state['message'] = 'O instalador foi encerrado antes de concluir. Consulte o log.';
        } elseif (($state['status'] ?? '') === 'starting') {
            $updatedAt = strtotime((string) ($state['updatedAt'] ?? '')) ?: 0;
            if ($updatedAt > 0 && time() - $updatedAt > 5) {
                $state['status'] = 'failed';
                $state['message'] = 'O instalador não confirmou a inicialização. Consulte o log.';
            }
        }
        $log = '';
        $logFile = $files['log'];
        if (is_file($logFile) && is_readable($logFile)) {
            $log = (string) @file_get_contents($logFile);
        }

        return [
            'status' => $state['status'] ?? 'idle',
            'message' => $state['message'] ?? 'Nenhum reparo executado nesta instalação.',
            'updatedAt' => $state['updatedAt'] ?? null,
            'exitCode' => $state['exitCode'] ?? null,
            'log' => $log,
        ];
    }

    private static function jobFiles(): array
    {
        $runtime = self::root() . '/.runtime';
        $runtimeFiles = [
            'state' => $runtime . '/codex-installer.json',
            'log' => $runtime . '/codex-installer.log',
            'lock' => $runtime . '/codex-installer.lock',
        ];
        if (self::jobFilesWritable($runtime, $runtimeFiles)) {
            return $runtimeFiles;
        }

        $userId = function_exists('posix_geteuid') ? (string) posix_geteuid() : (string) getmyuid();
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'lotus-filemanager-diagnostics-'
            . substr(hash('sha256', $userId . ':' . self::root()), 0, 24);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return $runtimeFiles;
        }
        @chmod($directory, 0700);

        return [
            'state' => $directory . '/codex-installer.json',
            'log' => $directory . '/codex-installer.log',
            'lock' => $directory . '/codex-installer.lock',
        ];
    }

    private static function jobFilesWritable(string $directory, array $files): bool
    {
        if (!is_dir($directory)) {
            return is_writable(dirname($directory));
        }
        if (!is_writable($directory)) {
            return false;
        }
        foreach ($files as $file) {
            if (file_exists($file) && !is_writable($file)) {
                return false;
            }
        }
        return true;
    }

    private static function replaceLog(string $file, string $message): void
    {
        if (@file_put_contents($file, $message, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível criar o log do instalador.');
        }
        @chmod($file, 0600);
    }

    private static function appendLog(string $file, string $message): void
    {
        @file_put_contents($file, $message, FILE_APPEND | LOCK_EX);
    }

    private static function launchDiagnostics(string $command): string
    {
        $userId = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $account = function_exists('posix_getpwuid') ? posix_getpwuid($userId) : false;
        $accountName = is_array($account) ? $account['name'] : (string) $userId;
        $selfStatus = (string) @file_get_contents('/proc/self/status');
        $selfThreads = self::statusNumber($selfStatus, 'Threads');
        [$userProcesses, $userThreads] = self::userProcessUsage($userId);
        $limits = function_exists('posix_getrlimit') ? posix_getrlimit() : [];
        $softMaxProcesses = is_array($limits) ? ($limits['soft maxproc'] ?? 'indisponível') : 'indisponível';
        $hardMaxProcesses = is_array($limits) ? ($limits['hard maxproc'] ?? 'indisponível') : 'indisponível';
        [$cgroupCurrent, $cgroupMaximum] = self::cgroupProcessLimits();
        $loadAverage = trim((string) @file_get_contents('/proc/loadavg'));
        $memoryInfo = (string) @file_get_contents('/proc/meminfo');
        $availableMemory = self::statusNumber($memoryInfo, 'MemAvailable');

        return implode("\n", [
            '[FileManager][launcher] PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), PID ' . getmypid()
                . ', usuário ' . $accountName . ' (' . $userId . ').',
            '[FileManager][launcher] Processo PHP: ' . $selfThreads . ' thread(s).',
            '[FileManager][launcher] Usuário: ' . $userProcesses . ' processo(s), '
                . $userThreads . ' thread(s) visíveis em /proc.',
            '[FileManager][launcher] Limite RLIMIT_NPROC: soft=' . $softMaxProcesses
                . ', hard=' . $hardMaxProcesses . '.',
            '[FileManager][launcher] Cgroup pids: atual=' . $cgroupCurrent
                . ', máximo=' . $cgroupMaximum . '.',
            '[FileManager][launcher] /proc/loadavg: ' . ($loadAverage !== '' ? $loadAverage : 'indisponível') . '.',
            '[FileManager][launcher] MemAvailable: '
                . ($availableMemory > 0 ? $availableMemory . ' kB' : 'indisponível') . '.',
            '[FileManager][launcher] Comando: ' . $command,
        ]) . "\n";
    }

    private static function userProcessUsage(int $userId): array
    {
        $processes = 0;
        $threads = 0;
        foreach (glob('/proc/[0-9]*/status') ?: [] as $statusFile) {
            $status = @file_get_contents($statusFile);
            if (!is_string($status)
                || preg_match('/^Uid:\s+(\d+)/m', $status, $matches) !== 1
                || (int) $matches[1] !== $userId) {
                continue;
            }
            $processes++;
            $threads += self::statusNumber($status, 'Threads');
        }
        return [$processes, $threads];
    }

    private static function cgroupProcessLimits(): array
    {
        $directories = ['/sys/fs/cgroup'];
        $cgroup = (string) @file_get_contents('/proc/self/cgroup');
        if (preg_match('/^0::(.+)$/m', $cgroup, $matches) === 1) {
            $directories[] = '/sys/fs/cgroup' . rtrim($matches[1], '/');
        }

        foreach (array_reverse(array_unique($directories)) as $directory) {
            $current = @file_get_contents($directory . '/pids.current');
            $maximum = @file_get_contents($directory . '/pids.max');
            if (is_string($current) || is_string($maximum)) {
                return [
                    is_string($current) ? trim($current) : 'indisponível',
                    is_string($maximum) ? trim($maximum) : 'indisponível',
                ];
            }
        }
        return ['indisponível', 'indisponível'];
    }

    private static function statusNumber(string $contents, string $name): int
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s+(\d+)/m', $contents, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    private static function writeJobState(string $file, array $state): void
    {
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $temporary = $file . '.tmp.' . getmypid();
        if ($encoded === false
            || @file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false
            || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException('Não foi possível registrar o estado do instalador.');
        }
        @chmod($file, 0600);
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

    private static function codexLoginActive(?string $binary): bool
    {
        if ($binary === null) {
            return false;
        }
        $path = self::root() . '/.runtime/node/bin' . PATH_SEPARATOR . (string) getenv('PATH');
        $command = 'env -u CODEX_ACCESS_TOKEN PATH=' . escapeshellarg($path) . ' '
            . escapeshellarg($binary) . ' login status >/dev/null 2>&1';
        exec($command, $output, $exitCode);
        return $exitCode === 0;
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
