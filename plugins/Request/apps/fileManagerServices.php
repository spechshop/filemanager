<?php

namespace plugins\Request;

use Swoole\Http\Request;
use Swoole\Http\Response;

class fileManagerServices
{
    private const PROJECT_ROOT = __DIR__ . '/../../../';

    private const SERVICES = [
        'pty' => [
            'name' => 'Terminal PTY',
            'description' => 'Sessões de terminal usadas pelo editor.',
            'port' => 6060,
            'log' => 'pty.log',
        ],
        'lsp' => [
            'name' => 'PHP Language Server',
            'description' => 'Autocomplete, diagnósticos e navegação pelo Intelephense.',
            'port' => 3057,
            'log' => 'lsp.log',
        ],
        'gpt' => [
            'name' => 'GPT Bridge',
            'description' => 'Serviço legado de integração do ChatGPT.',
            'port' => 3090,
            'log' => 'gpt.log',
        ],
        'codex' => [
            'name' => 'Codex Agent',
            'description' => 'Agente de tarefas autenticado pelo workspace ChatGPT Enterprise.',
            'port' => 3091,
            'log' => 'codex-agent.log',
        ],
    ];

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
                'services' => self::allStatuses(),
            ]);
        }

        if ($method !== 'POST') {
            return self::respond($response, 405, [
                'success' => false,
                'message' => 'Método não permitido.',
            ]);
        }

        $service = strtolower(trim((string) ($request->post['service'] ?? '')));
        $action = strtolower(trim((string) ($request->post['action'] ?? '')));
        if (!isset(self::SERVICES[$service]) || !in_array($action, ['start', 'stop', 'restart'], true)) {
            return self::respond($response, 422, [
                'success' => false,
                'message' => 'Serviço ou ação inválida.',
            ]);
        }

        try {
            $message = self::executeAction($service, $action);
            return self::respond($response, 200, [
                'success' => true,
                'message' => $message,
                'services' => self::allStatuses(),
            ]);
        } catch (\Throwable $exception) {
            return self::respond($response, 409, [
                'success' => false,
                'message' => $exception->getMessage(),
                'services' => self::allStatuses(),
            ]);
        }
    }

    private static function executeAction(string $service, string $action): string
    {
        $name = self::SERVICES[$service]['name'];

        if ($action === 'stop') {
            self::stop($service);
            fileManagerConfig::setServiceEnabled($service, false);
            return "$name interrompido.";
        }

        if ($action === 'restart') {
            self::stop($service);
        }

        self::start($service);
        fileManagerConfig::setServiceEnabled($service, true);

        return $action === 'restart' ? "$name reiniciado." : "$name iniciado.";
    }

    private static function allStatuses(): array
    {
        try {
            $config = fileManagerConfig::read();
        } catch (\Throwable) {
            $config = [];
        }

        $statuses = [];
        foreach (self::SERVICES as $id => $definition) {
            [$available, $reason] = self::availability($id);
            $statuses[] = [
                'id' => $id,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'port' => $definition['port'],
                'running' => self::portAlive($definition['port']),
                'enabled' => fileManagerConfig::serviceEnabled($config, $id),
                'available' => $available,
                'unavailableReason' => $reason,
            ];
        }

        return $statuses;
    }

    private static function availability(string $service): array
    {
        $root = self::root();
        $node = self::nodeBinary();

        if ($service === 'pty') {
            $nodePty = $node !== null
                && is_dir($root . '/node_modules/node-pty')
                && self::nodeCanRequire($node, ['node-pty']);
            $phpFallback = file_exists($root . '/pty.php') && extension_loaded('swoole');
            return ($nodePty || $phpFallback)
                ? [true, null]
                : [false, 'node-pty e o fallback PHP/Swoole não estão disponíveis.'];
        }

        if ($service === 'lsp') {
            $available = $node !== null
                && file_exists($root . '/lsp.js')
                && is_dir($root . '/node_modules/intelephense');
            return $available
                ? [true, null]
                : [false, 'Node.js ou Intelephense não está disponível.'];
        }

        if ($service === 'codex') {
            if ($node === null || !file_exists($root . '/codex-agent.js')) {
                return [false, 'Node.js ou codex-agent.js não está disponível.'];
            }
            if (!self::nodeSupportsEnvFile($node)) {
                return [false, 'O Codex Agent requer Node.js 20.12 ou mais recente.'];
            }
            if (!self::nodeCanRequire($node, ['ws'])) {
                return [false, 'A dependência Node.js ws não está instalada.'];
            }
            $codex = self::codexBinary();
            if ($codex === null) {
                return [false, 'O Codex CLI não está instalado ou não está no PATH.'];
            }
            if (!self::codexSupportsAccessTokens($codex)) {
                return [false, 'O token do workspace requer Codex CLI 0.138.0-alpha.6 ou mais recente.'];
            }
            if (!self::envHasValue($root . '/.env', 'CODEX_ACCESS_TOKEN')) {
                return [false, 'CODEX_ACCESS_TOKEN não foi definido no arquivo .env.'];
            }
            return [true, null];
        }

        $modules = ['puppeteer-extra', 'puppeteer-extra-plugin-stealth', 'ws', 'express'];
        $available = $node !== null
            && file_exists($root . '/gpt.js')
            && self::nodeCanRequire($node, $modules);
        return $available
            ? [true, null]
            : [false, 'As dependências do serviço GPT não estão instaladas.'];
    }

    private static function start(string $service): void
    {
        $definition = self::SERVICES[$service];
        if (self::portAlive($definition['port'])) {
            if (self::serviceProcessIds($service) !== []) {
                return;
            }
            throw new \RuntimeException(
                'A porta está ocupada por um processo que não pertence a esta instalação.'
            );
        }

        [$available, $reason] = self::availability($service);
        if (!$available) {
            throw new \RuntimeException((string) $reason);
        }

        $command = self::startCommand($service);
        $root = self::root();
        $log = $root . '/' . $definition['log'];
        $escapedCommand = implode(' ', array_map('escapeshellarg', $command));
        $shellCommand = 'cd ' . escapeshellarg($root)
            . ' && nohup ' . $escapedCommand
            . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        exec($shellCommand);

        if (!self::waitForPort($definition['port'], true, 6.0)) {
            throw new \RuntimeException(
                $definition['name'] . ' não iniciou. Consulte ' . basename($log) . '.'
            );
        }
    }

    private static function stop(string $service): void
    {
        $definition = self::SERVICES[$service];
        $pids = self::serviceProcessIds($service);

        if ($pids === []) {
            if (!self::portAlive($definition['port'])) {
                return;
            }
            throw new \RuntimeException(
                'A porta está ocupada por um processo que não pertence a esta instalação.'
            );
        }

        $targets = self::processTree($pids);
        foreach ($pids as $pid) {
            self::signal($pid, 15);
        }

        self::waitForPort($definition['port'], false, 3.0);
        foreach ($targets as $pid) {
            if (self::processExists($pid)) {
                self::signal($pid, 9);
            }
        }

        if (!self::waitForPort($definition['port'], false, 2.0)) {
            throw new \RuntimeException('Não foi possível interromper ' . $definition['name'] . '.');
        }
    }

    private static function startCommand(string $service): array
    {
        $root = self::root();
        $node = self::nodeBinary();

        if ($service === 'pty') {
            if ($node !== null && is_dir($root . '/node_modules/node-pty')
                && self::nodeCanRequire($node, ['node-pty'])) {
                return [$node, $root . '/pty.js'];
            }
            return [PHP_BINARY, $root . '/pty.php'];
        }

        if ($node === null) {
            throw new \RuntimeException('Node.js não está disponível.');
        }

        if ($service === 'codex') {
            return [
                $node,
                $root . '/codex-agent.js',
            ];
        }

        return [$node, $root . '/' . ($service === 'lsp' ? 'lsp.js' : 'gpt.js')];
    }

    private static function serviceProcessIds(string $service): array
    {
        $root = self::root();
        $scripts = match ($service) {
            'pty' => ['pty.js', 'pty.php'],
            'codex' => ['codex-agent.js'],
            default => [$service . '.js'],
        };
        $expected = array_map(static fn(string $script): string => $root . '/' . $script, $scripts);
        $pids = [];

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
            $pid = (int) basename(dirname($cmdlineFile));
            if ($pid <= 1 || $pid === getmypid()) {
                continue;
            }
            $raw = @file_get_contents($cmdlineFile);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $args = array_values(array_filter(
                explode("\0", $raw),
                static fn(string $argument): bool => $argument !== ''
            ));
            $executable = basename((string) @readlink("/proc/$pid/exe"));
            if ($executable === '') {
                $executable = basename($args[0] ?? '');
            }
            if (!str_starts_with($executable, 'node') && !str_starts_with($executable, 'php')) {
                continue;
            }
            $cwd = (string) @readlink("/proc/$pid/cwd");

            foreach (array_slice($args, 1) as $argument) {
                // Instalações antigas iniciavam `node pty` dentro de uma
                // sessão screen. Em /proc com hidepid talvez o cwd não seja
                // legível; o nome exclusivo da sessão identifica esse caso.
                if ($service === 'pty'
                    && in_array($argument, ['pty', 'pty.js'], true)
                    && self::parentCommandContains($pid, 'nodePTY')) {
                    $pids[] = $pid;
                    continue 2;
                }
                $candidates = str_starts_with($argument, '/')
                    ? [$argument]
                    : [$cwd . '/' . $argument, $cwd . '/' . $argument . '.js'];
                foreach ($candidates as $candidate) {
                    $resolved = realpath($candidate);
                    if ($resolved !== false && in_array($resolved, $expected, true)) {
                        $pids[] = $pid;
                        continue 3;
                    }
                }
            }
        }

        return array_values(array_unique($pids));
    }

    private static function parentCommandContains(int $pid, string $needle): bool
    {
        $status = @file_get_contents("/proc/$pid/status");
        if (!is_string($status) || !preg_match('/^PPid:\s+(\d+)/m', $status, $match)) {
            return false;
        }
        $cmdline = @file_get_contents('/proc/' . (int) $match[1] . '/cmdline');
        return is_string($cmdline) && str_contains(str_replace("\0", ' ', $cmdline), $needle);
    }

    private static function processTree(array $roots): array
    {
        $all = array_fill_keys($roots, true);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (glob('/proc/[0-9]*/status') ?: [] as $statusFile) {
                $pid = (int) basename(dirname($statusFile));
                $status = @file_get_contents($statusFile);
                if (!is_string($status) || !preg_match('/^PPid:\s+(\d+)/m', $status, $match)) {
                    continue;
                }
                if (isset($all[(int) $match[1]]) && !isset($all[$pid])) {
                    $all[$pid] = true;
                    $changed = true;
                }
            }
        }
        return array_map('intval', array_keys($all));
    }

    private static function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
            return;
        }
        exec('kill -' . $signal . ' ' . escapeshellarg((string) $pid));
    }

    private static function processExists(int $pid): bool
    {
        return $pid > 1 && is_dir("/proc/$pid");
    }

    private static function portAlive(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private static function waitForPort(int $port, bool $expected, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            if (self::portAlive($port) === $expected) {
                return true;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        return self::portAlive($port) === $expected;
    }

    private static function nodeCanRequire(string $node, array $modules): bool
    {
        static $resultCache = [];
        $cacheKey = $node . ':' . implode(',', $modules);
        if (array_key_exists($cacheKey, $resultCache)) {
            return $resultCache[$cacheKey];
        }

        $requires = implode(';', array_map(
            static fn(string $module): string => 'require(' . json_encode($module) . ')',
            $modules
        ));
        $command = 'cd ' . escapeshellarg(self::root()) . ' && '
            . escapeshellarg($node) . ' -e ' . escapeshellarg($requires) . ' >/dev/null 2>&1';
        exec($command, $output, $exitCode);
        return $resultCache[$cacheKey] = $exitCode === 0;
    }

    private static function nodeSupportsEnvFile(string $node): bool
    {
        $version = trim((string) shell_exec(escapeshellarg($node) . ' --version 2>/dev/null'));
        return preg_match('/^v(\d+)\.(\d+)/', $version, $matches) === 1
            && ((int) $matches[1] > 20
                || ((int) $matches[1] === 20 && (int) $matches[2] >= 12));
    }

    private static function codexBinary(): ?string
    {
        $configured = self::envValue(self::root() . '/.env', 'CODEX_BIN');
        if ($configured !== null && is_file($configured) && is_executable($configured)) {
            return $configured;
        }
        $binary = trim((string) shell_exec('command -v codex 2>/dev/null'));
        if ($binary !== '') {
            return $binary;
        }
        $userHome = getenv('HOME');
        $candidates = array_filter([
            is_string($userHome) && $userHome !== '' ? $userHome . '/.local/bin/codex' : null,
            '/usr/local/bin/codex',
            '/usr/bin/codex',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function codexSupportsAccessTokens(string $binary): bool
    {
        $output = trim((string) shell_exec(escapeshellarg($binary) . ' --version 2>/dev/null'));
        if (!preg_match('/\b(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\b/', $output, $matches)) {
            return false;
        }
        return version_compare($matches[1], '0.138.0-alpha.6', '>=');
    }

    private static function envHasValue(string $file, string $name): bool
    {
        return self::envValue($file, $name) !== null;
    }

    private static function envValue(string $file, string $name): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (!preg_match('/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*(.*)$/', $line, $match)) {
                    continue;
                }
                $value = trim($match[1]);
                if (strlen($value) >= 2) {
                    $quote = $value[0];
                    if (($quote === '"' || $quote === "'") && $value[-1] === $quote) {
                        $value = substr($value, 1, -1);
                    }
                }
                return $value !== '' ? $value : null;
            }
        } finally {
            fclose($handle);
        }
        return null;
    }

    private static function nodeBinary(): ?string
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        return $node !== '' ? $node : null;
    }

    private static function root(): string
    {
        return rtrim((string) realpath(self::PROJECT_ROOT), '/');
    }

    private static function respond(Response $response, int $status, array $payload): bool
    {
        $response->status($status);
        return $response->end(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
