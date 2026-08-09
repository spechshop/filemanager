<?php

use libspech\Cache\cache as cacheLibSpech;
use plugins\Start\cache;
use Swoole\WebSocket\Server;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
global $server;
global $coroutinesProcess;
ini_set('memory_limit', '2000M');
ini_set('max_input_vars', '100000');
include 'libspech/plugins/autoloader.php';
require_once __DIR__ . '/vendor/autoload.php';
sleep(1);
include 'plugins/autoload.php';
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

if (!function_exists('startPtyServer')) {
    /**
     * Inicia o servidor de PTY conforme a escolha salva no painel. O modo
     * automático tenta Node.js primeiro e usa PHP/Swoole como fallback.
     */
    function startPtyServer(): void
    {
        $hasScreen = trim((string) shell_exec('command -v screen 2>/dev/null')) !== '';
        $backend = fileManagerPtyBackend();
        $node = $backend !== 'php' ? fileManagerPtyNodeBinary() : '';
        if ($node !== '') {
            echo "Iniciando PTY via node (node-pty)...\n";
            if ($hasScreen) {
                shell_exec('screen -dmS nodePTY ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/pty.js'));
            } else {
                shell_exec('nohup ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/pty.js') . ' >> ' . escapeshellarg(__DIR__ . '/pty.log') . ' 2>&1 &');
            }
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
        if ($hasScreen) {
            shell_exec('screen -dmS phpPTY ' . escapeshellarg($php) . ' ' . escapeshellarg($ptyPhp));
        } else {
            shell_exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($ptyPhp) . ' >> ' . escapeshellarg(__DIR__ . '/pty.log') . ' 2>&1 &');
        }
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
        $hasScreen = trim((string) shell_exec('command -v screen 2>/dev/null')) !== '';
        echo "Iniciando LSP PHP (Intelephense) via node...\n";
        if ($hasScreen) {
            shell_exec('screen -dmS nodeLSP ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/lsp.js'));
        } else {
            shell_exec('nohup ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/lsp.js') . ' >> ' . escapeshellarg(__DIR__ . '/lsp.log') . ' 2>&1 &');
        }
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
        $contents = @file_get_contents(__DIR__ . '/plugins/configInterface.json');
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
        $contents = @file_get_contents(__DIR__ . '/plugins/configInterface.json');
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
        $hasScreen = trim((string) shell_exec('command -v screen 2>/dev/null')) !== '';
        echo "Iniciando GPT Bridge via node...\n";
        if ($hasScreen) {
            shell_exec('screen -dmS nodeGPT ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/gpt.js'));
        } else {
            shell_exec('nohup ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/gpt.js') . ' >> ' . escapeshellarg(__DIR__ . '/gpt.log') . ' 2>&1 &');
        }
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
        $command = escapeshellarg($node) . ' ' . escapeshellarg($script);
        $hasScreen = trim((string) shell_exec('command -v screen 2>/dev/null')) !== '';
        echo "Iniciando Codex Agent via app-server...\n";
        if ($hasScreen) {
            shell_exec('screen -dmS nodeCodexAgent ' . $command);
        } else {
            shell_exec('nohup ' . $command . ' >> ' . escapeshellarg(__DIR__ . '/codex-agent.log') . ' 2>&1 &');
        }
    }
}
co\run(function () {
    if (fileManagerServiceEnabled('pty') && !portAlive(6060)) {
        startPtyServer();
    } elseif (!fileManagerServiceEnabled('pty')) {
        echo "PTY desativado nas configurações do File Manager.\n";
    } else {
        echo "Port 6060 is already in use.\n";
    }
    if (fileManagerServiceEnabled('lsp') && !portAlive(3057)) {
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
    if (fileManagerServiceEnabled('codex') && !portAlive(3091)) {
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

$server->set($serverSettings);
$server->on('open', '\plugins\server::open');
$server->on('message', '\plugins\server::message');
$server->on('close', '\plugins\server::close');
$server->on('Start', '\plugins\Start\server::start');
$server->on('Request', '\plugins\Request\server::request');
$server->start();
