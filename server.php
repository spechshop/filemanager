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
    $localPhp = $cwd . '/php';

    // Verifica se o ./php local existe e tem Swoole
    if (!file_exists($localPhp) || !is_executable($localPhp)) {
        fwrite(STDERR, "[server.php] Swoole não encontrado e ./php não está disponível. Instale o Swoole ou adicione-o ao PATH.\n");
        exit(1);
    }

    $hasSwoole = trim((string) shell_exec(escapeshellarg($localPhp) . ' --ri swoole 2>/dev/null'));
    if (empty($hasSwoole)) {
        fwrite(STDERR, "[server.php] ./php também não possui Swoole. Não é possível continuar.\n");
        exit(1);
    }

    // ---------------------------------------------------------
    // Persistência: nas próximas vezes que o usuário entrar
    // nesta pasta e rodar "php ...", o ./php local será usado.
    // ---------------------------------------------------------
    $escapedCwd = str_replace('"', '\\"', $cwd);
    $marker     = '# filemanager-local-php';
    $rcBlock    = "\n$marker\n"
        . "if [ \"\$PWD\" = \"$escapedCwd\" ] || echo \"\$PWD\" | grep -q \"^$escapedCwd\"; then\n"
        . "  export PATH=\"$escapedCwd:\$PATH\"\n"
        . "fi\n";

    // .bashrc / .zshrc / .profile
    $home = getenv('HOME') ?: '/root';
    foreach (['.bashrc', '.zshrc', '.profile'] as $rc) {
        $rcPath = "$home/$rc";
        if (file_exists($rcPath)) {
            $content = file_get_contents($rcPath);
            if (strpos($content, $marker) === false) {
                file_put_contents($rcPath, $content . $rcBlock);
            }
        }
    }

    // .envrc para quem usa direnv
    $envrc = $cwd . '/.envrc';
    $envrcLine = "export PATH=\"$escapedCwd:\$PATH\"\n";
    if (!file_exists($envrc) || strpos((string) file_get_contents($envrc), $envrcLine) === false) {
        file_put_contents($envrc, $envrcLine, FILE_APPEND);
    }

    fwrite(STDOUT, "[server.php] Sistema PHP sem Swoole. Re-executando com ./php local e persistindo PATH...\n");

    // ---------------------------------------------------------
    // Re-executa este mesmo script com o ./php local.
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
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
use plugins\Start\console as consoleDeclares;
use Swoole\Coroutine as co;
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



include 'libspech/plugins/autoloader.php';
\libspech\Cli\cli::pcl("Running Tests...");
\co\run(function () use ($phpBinary) {
    global $argv;
    if (@$argv[1] !== '--fix')
    \libspech\Cli\cli::pcl(shell_exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg(__DIR__ . '/run-tests.php')));
    $fixs = 'fixs.json';
    if (file_exists($fixs)) {
        $r = json_decode(file_get_contents($fixs), true)['fixes'];
        print "Running fixes...\n";
        foreach ($r as $fix) {
            foreach ($fix['commands'] as $command) {
              print  shell_exec($command);
            }
        }
    }

});


require_once __DIR__ . '/vendor/autoload.php';
include_once 'plugins/autoload.php';

function fileManagerAutoRestartEnabled(): bool
{
    $configPath = __DIR__ . '/plugins/configInterface.json';
    $contents = @file_get_contents($configPath);
    $config = is_string($contents) ? json_decode($contents, true) : null;

    // Mantém o comportamento anterior quando a chave ainda não existe.
    return !is_array($config)
        || ($config['fileManager']['autoRestart'] ?? true) !== false;
}

for (; ;) {
    print "Starting server...\n";
    $sharedPid = null;
    $pidRunner = null;
    Co\run(function () use (&$sharedPid, &$pidRunner, $phpBinary) {
        \plugins\terminal::asyncShell(escapeshellarg($phpBinary) . ' ' . escapeshellarg(__DIR__ . '/middleware.php'), (new consoleDeclares()), $sharedPid);
    });

    Co\run(fn() => co::sleep(3));
    print "Middleware stopped ($sharedPid, $pidRunner). Cleaning up...\n";
    \plugins\terminal::pKill($sharedPid);

    if (!fileManagerAutoRestartEnabled()) {
        print "Autorestart disabled in File Manager settings. Supervisor stopped.\n";
        break;
    }

    print "Restarting middleware...\n";
}
