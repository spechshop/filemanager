<?php

// ============================================================
// BLOCO 1: garantir que este processo rode com Swoole.
// Deve ser a PRIMEIRA coisa do arquivo, antes de qualquer uso
// de classes Swoole.
// ============================================================

(function () {
    if (extension_loaded('swoole')) {
        return; // já estamos rodando com Swoole, ok
    }

    $cwd      = __DIR__;
    $localPhp = $cwd . '/pcg';

    // Verifica se o runtime ./pcg local existe e tem Swoole.
    if (!file_exists($localPhp) || !is_executable($localPhp)) {
        fwrite(STDERR, "[server.php] Swoole não encontrado e o runtime ./pcg não está disponível. Execute o installer.sh.\n");
        exit(1);
    }

    $hasSwoole = trim((string) shell_exec(escapeshellarg($localPhp) . ' --ri swoole 2>/dev/null'));
    if (empty($hasSwoole)) {
        fwrite(STDERR, "[server.php] O runtime ./pcg não possui Swoole. Não é possível continuar.\n");
        exit(1);
    }

    fwrite(STDOUT, "[server.php] PHP atual sem Swoole. Re-executando com o runtime isolado ./pcg...\n");

    // ---------------------------------------------------------
    // Re-executa este mesmo script com o ./pcg local.
    // pcntl_exec substitui o processo atual (sem fork).
    // ---------------------------------------------------------
    if (function_exists('pcntl_exec')) {
        pcntl_exec($localPhp, $GLOBALS['argv']);
        // se pcntl_exec retornou, algo deu errado — fallback
    }

    // Fallback: passthru (cria subprocesso filho)
    $args = implode(' ', array_map('escapeshellarg', array_slice($GLOBALS['argv'], 0)));
    passthru(escapeshellarg($localPhp) . ' ' . $args, $exitCode);
    exit($exitCode);
})();

// ============================================================
// BLOCO 2: a partir daqui o Swoole está garantido.
// ============================================================
// This process is a native, signal-driven supervisor, not a coroutine server.
// Hooking proc_open/pipe IO here requires a scheduler and prevents reliable
// pcntl shutdown. middleware.php enables its own hooks in a separate runtime.
\Swoole\Runtime::enableCoroutine(0);
use plugins\Start\console as consoleDeclares;
$phpBinary = PHP_BINARY;

function portAlive(mixed $port): bool
{
    $host = "0.0.0.0";
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    var_dump($fp);
    if (!$fp) {
        return false;
    }
    fclose($fp);
    return true;
}



$deploymentAutoloader = __DIR__ . '/libspech/plugins/autoloader.php';
if (!is_file($deploymentAutoloader)) {
    throw new RuntimeException('Deployment dependency libspech is missing; use the documented installer in an isolated environment.');
}
require $deploymentAutoloader;
// Diagnostics and migrations are explicit CLI operator actions. Never execute
// commands from fixs.json or run installation/repair while starting HTTP.
require_once __DIR__ . '/vendor/autoload.php';
include_once 'plugins/autoload.php';

function fileManagerAutoRestartEnabled(): bool
{
    $configPath = getenv('FILEMANAGER_CONFIG_FILE') ?: __DIR__ . '/plugins/configInterface.json';
    $contents = @file_get_contents($configPath);
    $config = is_string($contents) ? json_decode($contents, true) : null;

    // Mantém o comportamento anterior quando a chave ainda não existe.
    return !is_array($config)
        || ($config['fileManager']['autoRestart'] ?? true) !== false;
}

$stopping = false;
$sharedPid = null;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stopSupervisor = static function () use (&$stopping, &$sharedPid): void {
        $stopping = true;
        if (is_int($sharedPid) && $sharedPid > 1) {
            posix_kill($sharedPid, SIGTERM);
        }
    };
    pcntl_signal(SIGTERM, $stopSupervisor, false);
    pcntl_signal(SIGINT, $stopSupervisor, false);
}

while (!$stopping) {
    print "Starting server...\n";
    $sharedPid = null;
    // Native blocking pipe IO lets pcntl deliver termination signals while the
    // supervisor is asleep. The argv array makes the child PID the PHP process,
    // without an intermediate shell that could leave middleware orphaned.
    \plugins\terminal::asyncShell(
        [$phpBinary, __DIR__ . '/middleware.php'],
        new consoleDeclares(),
        $sharedPid
    );

    $sharedPid = null;
    if ($stopping) break;
    print "Middleware stopped. Cleaning up...\n";

    $explicitRestart = \plugins\Request\fileManagerRuntime::consumeRestartRequest();
    if (!$explicitRestart && !fileManagerAutoRestartEnabled()) {
        print "Autorestart disabled in File Manager settings. Supervisor stopped.\n";
        break;
    }

    print $explicitRestart
        ? "Explicit restart requested. Restarting middleware...\n"
        : "Restarting middleware...\n";
    // Native sleep is interruptible by the same stop handler. The loop checks
    // the stop flag again before launching a child after this restart backoff.
    sleep(3);
}
