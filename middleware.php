<?php

use libspech\Cache\cache as cacheLibSpech;
use plugins\Start\cache;
use Swoole\WebSocket\Server;
Swoole\Runtime::enableCoroutine(0);
global $server;
global $coroutinesProcess;
ini_set('memory_limit', '2000M');
ini_set('max_input_vars', '100000');
$deploymentAutoloader = __DIR__ . '/libspech/plugins/autoloader.php';
if (!is_file($deploymentAutoloader)) {
    throw new RuntimeException('Deployment dependency libspech is missing; use the documented installer in an isolated environment.');
}
require $deploymentAutoloader;
require_once __DIR__ . '/vendor/autoload.php';
sleep(1);
include 'plugins/autoload.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$serverSettings = cacheLibSpech::get('interface');
$interfacetr = cacheLibSpech::get('interface');
if (cacheLibSpech::get('interface')['ssl']) {
    if (array_key_exists('ssl_cert_file', $serverSettings['serverSettings'])) {
        if (!file_exists(cacheLibSpech::get('interface')['serverSettings']['ssl_cert_file'])) {
            $keyFile = $interfacetr['serverSettings']['ssl_key_file'];
            $certFile = $interfacetr['serverSettings']['ssl_cert_file'];
            \libspech\Cli\cli::pcl("Generating SSL certificates...");
            \libspech\Cli\cli::pcl("Arquivos: {$keyFile}, {$certFile}");
            // Gerar chave privada e certificado em arquivos separados
            shell_exec('openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout ' . escapeshellarg($keyFile) . ' -out ' . escapeshellarg($certFile) . ' -subj "/C=BR/ST=State/L=City/O=Organization/OU=Unit/CN=localhost" 2>&1');
            sleep(4);
            // Aguardar a criação dos arquivos
            $maxWait = 10;
            $waited = 0;
            while ($waited < $maxWait) {
                if (file_exists($certFile) && file_exists($keyFile)) {
                    break;
                }
                sleep(1);
                $waited++;
            }
            if (!file_exists($certFile) || !file_exists($keyFile)) {
                throw new Error("Falha ao gerar certificados SSL. Verifique se o OpenSSL está instalado.");
            } else {
                $serverSettings = cacheLibSpech::get('interface')['serverSettings'];
                $serverSettings['ssl_cert_file'] = $certFile;
                $serverSettings['ssl_key_file'] = $keyFile;
            }
        }
    } else {
        throw new Error("INVALID SSL CONFIGURATION: ssl_cert_file and ssl_key_file must be set in interface.json");
    }
}

if (!function_exists('portAlive')) {
    function portAlive(mixed $port): bool
    {
        $host = "0.0.0.0";
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if (!$fp) {
            return false;
        }
        fclose($fp);
        return true;
    }
}

$secretDirectory = getenv('FILEMANAGER_DATA_DIR') ?: __DIR__ . '/.runtime';
if (!getenv('FILEMANAGER_BRIDGE_SECRET') && !is_dir($secretDirectory)) mkdir($secretDirectory, 0700, true);
$secretFile = $secretDirectory . '/bridge-secret';
if (!getenv('FILEMANAGER_BRIDGE_SECRET') && !is_file($secretFile)) {
    $handle = fopen($secretFile, 'x');
    if ($handle === false) throw new RuntimeException('Cannot initialize bridge authentication');
    chmod($secretFile, 0600);
    fwrite($handle, bin2hex(random_bytes(32)));
    fclose($handle);
}
if (strlen(\plugins\Request\securityPolicy::bridgeSecret()) < 32) throw new RuntimeException('Invalid bridge secret');
$host = cache::global()['interface']['host'];
$port = cache::global()['interface']['port'];
\co\run(function () use (&$port) {
    $free = portAlive($port);
    if ($free) {
        $sock = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, SOL_TCP);
        $sock->bind('0.0.0.0', 0);
        $s = (array) $sock->getsockname();
        cacheLibSpech::subDefine('interface', 'port', $s['port']);
        $port = cache::global()['interface']['port'];
        $sock->close();
        \libspech\Cli\cli::pcl("Porta {$port} disponível", 'bold_green');
    }
});
try {
    $server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
} catch (Throwable $e) {
    echo "❌ Erro ao iniciar o servidor: {$e->getMessage()}\n";
    sleep(1);
    exit(1);
}

if (!function_exists('fileManagerNodeBinary')) {
    function fileManagerNodeBinary(): string
    {
        $managed = __DIR__ . '/.runtime/node/bin/node';
        if (is_file($managed) && is_executable($managed)) {
            $version = trim((string) shell_exec(escapeshellarg($managed) . ' --version 2>/dev/null'));
            if (preg_match('/^v(\d+)\./', $version, $matches) === 1 && (int) $matches[1] >= 22) {
                return $managed;
            }
        }
        return trim((string) shell_exec('command -v node 2>/dev/null'));
    }
}

if (!function_exists('fileManagerPtyNodeBinary')) {
    /**
     * O node-pty é nativo e pode ter sido compilado para o Node do sistema,
     * enquanto o runtime gerenciado usa outra ABI. Testamos ambos e usamos
     * efetivamente aquele que consegue carregar o módulo.
     */
    function fileManagerPtyNodeBinary(): string
    {
        $managed = __DIR__ . '/.runtime/node/bin/node';
        $system = trim((string) shell_exec('command -v node 2>/dev/null'));
        $candidates = array_values(array_unique(array_filter([
            is_file($managed) && is_executable($managed) ? $managed : null,
            $system !== '' ? $system : null,
        ])));
        if (!is_dir(__DIR__ . '/node_modules/node-pty') || !file_exists(__DIR__ . '/pty.js')) {
            return '';
        }
        foreach ($candidates as $nodeBin) {
            $check = trim((string) shell_exec(
                'cd ' . escapeshellarg(__DIR__) . ' && ' . escapeshellarg($nodeBin)
                . ' -e "require(\'node-pty\')" >/dev/null 2>&1 && echo ok'
            ));
            if ($check === 'ok') {
                return $nodeBin;
            }
        }
        return '';
    }
}

if (!function_exists('nodePtyAvailable')) {
    function nodePtyAvailable(): bool
    {
        return fileManagerPtyNodeBinary() !== '';
    }
}

/**
 * Register foreground services before Server::start(). Swoole's manager owns
 * these PIDs, restarts failed services, and sends SIGTERM + waitpid on shutdown.
 * exec replaces the user process: there is no shell/screen/nohup child to orphan.
 */
function fileManagerAuxiliaryProcess(array $command): void
{
    global $server;
    $process = new \Swoole\Process(static function (\Swoole\Process $process) use ($command): void {
        // The callback runs in a dedicated user process, outside HTTP workers.
        // It must exec in native mode even if the HTTP runtime enables hooks.
        \Swoole\Runtime::enableCoroutine(0);
        $process->exec($command[0], array_slice($command, 1));
        throw new RuntimeException('Unable to execute auxiliary service');
    }, false, 0, false);
    if ($server->addProcess($process) === false) {
        throw new RuntimeException('Unable to register auxiliary service');
    }
}

if (!function_exists('startPtyServer')) {
    /**
     * Inicia o servidor de PTY conforme a escolha salva no painel. O modo
     * automático tenta Node.js primeiro e usa PHP/Swoole como fallback.
     */
    function startPtyServer(): void
    {
        $backend = fileManagerPtyBackend();
        $node = $backend !== 'php' ? fileManagerPtyNodeBinary() : '';
        if ($node !== '') {
            echo "Iniciando PTY via node (node-pty)...\n";
            fileManagerAuxiliaryProcess([$node, __DIR__ . '/pty.js']);
            return;
        }
        if ($backend === 'node') {
            echo "PTY Node.js foi selecionado, mas nenhum runtime conseguiu carregar node-pty.\n";
            return;
        }
        // Fallback em Swoole: usa o binário PHP atual (com Swoole garantido).
        $php = PHP_BINARY;
        $ptyPhp = __DIR__ . '/pty.php';
        if (!file_exists($ptyPhp) || !extension_loaded('swoole')) {
            echo "PTY PHP/Swoole foi selecionado, mas não está disponível.\n";
            return;
        }
        echo $backend === 'php'
            ? "Iniciando PTY via PHP/Swoole...\n"
            : "node-pty indisponível; usando fallback em Swoole (pty.php)...\n";
        fileManagerAuxiliaryProcess([$php, $ptyPhp]);
    }
}

if (!function_exists('startLspServer')) {
    /**
     * Sobe o bridge do Language Server PHP (lsp.js -> Intelephense) na
     * porta 3057. É a fonte de inteligência do editor (autocomplete,
     * hover/documentação, assinatura de parâmetros e diagnósticos),
     * substituindo o antigo stubs-generated.json.
     */
    function startLspServer(): void
    {
        $node = fileManagerNodeBinary();
        if ($node === '') {
            echo "node indisponível; LSP (Intelephense) não será iniciado.\n";
            return;
        }
        if (!is_dir(__DIR__ . '/node_modules/intelephense')) {
            echo "Intelephense não instalado (npm install intelephense); LSP desativado.\n";
            return;
        }
        echo "Iniciando LSP PHP (Intelephense) via node...\n";
        fileManagerAuxiliaryProcess([$node, __DIR__ . '/lsp.js']);
    }
}

if (!function_exists('fileManagerServiceEnabled')) {
    function fileManagerServiceEnabled(string $service): bool
    {
        $defaults = [
            'pty' => true,
            'lsp' => true,
            'gpt' => false,
            'codex' => false
        ];
        $contents = @file_get_contents(getenv('FILEMANAGER_CONFIG_FILE') ?: __DIR__ . '/plugins/configInterface.json');
        $config = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($config)) {
            return $defaults[$service] ?? false;
        }
        return ($config['fileManager']['services'][$service] ?? $defaults[$service] ?? false) !== false;
    }
}

if (!function_exists('fileManagerPtyBackend')) {
    function fileManagerPtyBackend(): string
    {
        $contents = @file_get_contents(getenv('FILEMANAGER_CONFIG_FILE') ?: __DIR__ . '/plugins/configInterface.json');
        $config = is_string($contents) ? json_decode($contents, true) : null;
        $backend = is_array($config)
            ? strtolower(trim((string) ($config['fileManager']['ptyBackend'] ?? 'auto')))
            : 'auto';
        return in_array($backend, ['auto', 'node', 'php'], true) ? $backend : 'auto';
    }
}

if (!function_exists('startGptServer')) {
    function startGptServer(): void
    {
        $node = fileManagerNodeBinary();
        if ($node === '' || !file_exists(__DIR__ . '/gpt.js')) {
            echo "Node.js ou gpt.js indisponível; GPT Bridge não será iniciado.\n";
            return;
        }
        echo "Iniciando GPT Bridge via node...\n";
        fileManagerAuxiliaryProcess([$node, __DIR__ . '/gpt.js']);
    }
}

if (!function_exists('startCodexAgentServer')) {
    function startCodexAgentServer(): void
    {
        $node = fileManagerNodeBinary();
        $script = __DIR__ . '/codex-agent.js';
        if ($node === '' || !file_exists($script)) {
            echo "Node.js ou codex-agent.js indisponível; Codex Agent não será iniciado.\n";
            return;
        }
        // codex-agent.js carrega CODEX_ACCESS_TOKEN e CODEX_BIN diretamente do .env.
        echo "Iniciando Codex Agent via app-server...\n";
        fileManagerAuxiliaryProcess([$node, $script]);
    }
}
co\run(function () {
    if (fileManagerServiceEnabled('pty') && !portAlive((int) (getenv('FILEMANAGER_PTY_PORT') ?: 6060))) {
        startPtyServer();
    } elseif (!fileManagerServiceEnabled('pty')) {
        echo "PTY desativado nas configurações do File Manager.\n";
    } else {
        echo "Port 6060 is already in use.\n";
    }
    if (fileManagerServiceEnabled('lsp') && !portAlive((int) (getenv('FILEMANAGER_LSP_PORT') ?: 3057))) {
        startLspServer();
    } elseif (!fileManagerServiceEnabled('lsp')) {
        echo "LSP desativado nas configurações do File Manager.\n";
    } else {
        echo "Port 3057 is already in use.\n";
    }
    if (fileManagerServiceEnabled('gpt') && !portAlive(3090)) {
        startGptServer();
    } elseif (!fileManagerServiceEnabled('gpt')) {
        echo "GPT Bridge desativado nas configurações do File Manager.\n";
    } else {
        echo "Port 3090 is already in use.\n";
    }
    if (fileManagerServiceEnabled('codex') && !portAlive((int) (getenv('CODEX_AGENT_PORT') ?: 3091))) {
        startCodexAgentServer();
    } elseif (!fileManagerServiceEnabled('codex')) {
        echo "Codex Agent desativado nas configurações do File Manager.\n";
    } else {
        echo "Port 3091 is already in use.\n";
    }
});
if (cacheLibSpech::get('interface')['ssl']) {
    $serverSettings = cacheLibSpech::get('interface')['serverSettings'];
}

// Swoole 6 streams multipart files separately from the normal package budget.
$uploadPort = (int) (getenv('FILEMANAGER_UPLOAD_PORT') ?: 0);
$serverSettings['package_max_length'] = \plugins\Request\securityPolicy::limit($uploadPort > 0 ? 'HTTP_BODY_BYTES' : 'UPLOAD_BYTES', $uploadPort > 0 ? 8388608 : 2147483648);
if ($uploadPort > 0) {
    $uploadListener = $server->listen('127.0.0.1', $uploadPort, SWOOLE_SOCK_TCP);
    if ($uploadListener === false) throw new RuntimeException('Cannot start private upload listener');
    $uploadListener->set(['open_http_protocol' => true, 'open_websocket_protocol' => false, 'package_max_length' => \plugins\Request\securityPolicy::limit('UPLOAD_BYTES', 2147483648)]);
}
$uploadTemporary = \plugins\Request\securityPolicy::uploadDirectory();
if (!is_dir($uploadTemporary) && !mkdir($uploadTemporary, 0700, true) && !is_dir($uploadTemporary)) throw new RuntimeException('Cannot prepare private upload directory');
$serverSettings['upload_tmp_dir'] = $uploadTemporary;
$serverSettings['upload_max_filesize'] = \plugins\Request\securityPolicy::limit('UPLOAD_BYTES', 2147483648);
$serverSettings['socket_buffer_size'] = \plugins\Request\securityPolicy::limit('OUTPUT_BUFFER_BYTES', 4194304);
$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('close', '\plugins\server::close');
$server->on('Start', '\plugins\Start\server::start');
$server->on('BeforeShutdown', '\plugins\Start\server::stop');
$server->on('WorkerExit', '\plugins\server::stopWorker');
$server->on('Request', '\plugins\Request\server::request');
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$server->start();
