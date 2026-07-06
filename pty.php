<?php
// =====================================================================
// pty.php - Fallback em Swoole (PHP) para o pty.js (node-pty).
//
// Objetivo: quando o ambiente não tem root/npm ou o node-pty não pode
// ser compilado, este servidor substitui o pty.js reproduzindo o mais
// fielmente possível o seu comportamento:
//   - Servidor WebSocket em 127.0.0.1:6060 (somente local).
//   - Cada conexão usa o "path" da URL como token da sessão.
//   - Terminal PTY REAL (bash interativo) por token, criado via
//     proc_open com descritores 'pty' (equivalente ao node-pty).
//   - Sessões persistentes: sobrevivem à desconexão do cliente e são
//     retomadas com replay do cache de saída.
//   - Protocolo idêntico ao pty.js:
//       startXtermHandlerCommand   -> reenvia cache de saída
//       closeXtermHandlerCommand   -> encerra a sessão
//       resizeXtermHandlerCommand  -> próximas 2 mensagens = cols, rows
//       qualquer outra mensagem    -> é escrita no terminal
//   - Persistência dos ids em terminals.json (igual ao pty.js).
//
// Requisitos: extensão Swoole (já garantida pelo ./php do projeto),
// proc_open com suporte a PTY (Linux) e o utilitário "stty" para resize.
// =====================================================================

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

const PTY_HOST         = '127.0.0.1';
const PTY_PORT         = 6060;
const OUTPUT_CACHE_SIZE = 50000; // bytes mantidos por terminal para replay
const TERMINALS_FILE   = './terminals.json';
const DEFAULT_COLS     = 220;
const DEFAULT_ROWS     = 30;

$shell = (stripos(PHP_OS, 'WIN') === 0) ? 'powershell.exe' : 'bash';

// Diretório onde os terminais iniciam (equivalente ao filesDir do pty.js).
$filesDir = getcwd() . '/files';
if (!is_dir($filesDir)) {
    @mkdir($filesDir, 0777, true);
    echo "Directory \"files\" created.\n";
}

// ---------------------------------------------------------------------
// Estado global (processo único: worker_num = 1)
// ---------------------------------------------------------------------
/**
 * $sessions[$token] = [
 *   'proc'   => resource proc_open,
 *   'master' => stream (master do PTY, leitura e escrita),
 *   'pid'    => int,
 *   'pts'    => string (/dev/pts/N) para resize via stty,
 *   'cache'  => string (replay),
 *   'termcw' => int (máquina de estados de resize: 0|1|2),
 *   'cols'   => int,
 *   'rows'   => int,
 *   'fd'     => int|null (fd do cliente WS atualmente conectado),
 * ]
 */
$GLOBALS['sessions']    = [];
$GLOBALS['fdToToken']   = [];
$GLOBALS['terminalIds'] = loadTerminalsFromFile();

function loadTerminalsFromFile(): array
{
    if (is_file(TERMINALS_FILE)) {
        try {
            $data = json_decode((string) file_get_contents(TERMINALS_FILE), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error loading terminals file: ' . $e->getMessage() . "\n");
        }
    }
    return [];
}

function saveTerminalsToFile(array $ids): void
{
    try {
        file_put_contents(TERMINALS_FILE, json_encode(array_values($ids), JSON_PRETTY_PRINT));
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Error saving terminals file: ' . $e->getMessage() . "\n");
    }
}

// ---------------------------------------------------------------------
// Criação / retomada de terminais
// ---------------------------------------------------------------------
function createNewTerminal(Server $server, string $token): bool
{
    global $shell, $filesDir;

    $descriptorspec = [['pty'], ['pty'], ['pty']];

    // Herdar o ambiente atual e ajustar TERM/COLUMNS/LINES.
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['TERM']    = 'xterm-color';
    $env['COLUMNS'] = (string) DEFAULT_COLS;
    $env['LINES']   = (string) DEFAULT_ROWS;

    $pipes = [];
    $proc  = @proc_open($shell, $descriptorspec, $pipes, $filesDir, $env);
    if (!is_resource($proc)) {
        fwrite(STDERR, "[pty.php] Falha ao abrir PTY para $token\n");
        return false;
    }

    $master = $pipes[0]; // Com PTY, o master é compartilhado (leitura e escrita).
    stream_set_blocking($master, false);

    $status = proc_get_status($proc);
    $pid    = $status['pid'] ?? 0;

    // Descobrir o /dev/pts/N do shell para permitir resize via stty.
    $pts = '';
    if ($pid > 0) {
        $link = @readlink("/proc/{$pid}/fd/0");
        if (is_string($link) && strncmp($link, '/dev/pts/', 9) === 0) {
            $pts = $link;
        }
    }

    $GLOBALS['sessions'][$token] = [
        'proc'   => $proc,
        'master' => $master,
        'pid'    => $pid,
        'pts'    => $pts,
        'cache'  => '',
        'termcw' => 0,
        'cols'   => DEFAULT_COLS,
        'rows'   => DEFAULT_ROWS,
        'fd'     => null,
    ];

    // Tamanho inicial da janela (equivalente ao cols/rows do node-pty).
    applyResize($token, DEFAULT_COLS, DEFAULT_ROWS);

    // Ler a saída do master de forma assíncrona pelo reactor do Swoole.
    Swoole\Event::add($master, function ($master) use ($server, $token) {
        onMasterReadable($server, $token, $master);
    });

    if (!in_array($token, $GLOBALS['terminalIds'], true)) {
        $GLOBALS['terminalIds'][] = $token;
        saveTerminalsToFile($GLOBALS['terminalIds']);
    }

    echo "New session created for {$token}\n";
    return true;
}

// Callback do reactor: há dados prontos no master do PTY.
function onMasterReadable(Server $server, string $token, $master): void
{
    if (!isset($GLOBALS['sessions'][$token])) {
        @Swoole\Event::del($master);
        return;
    }

    $data = @fread($master, 65536);

    // EOF / processo encerrado: limpar a sessão.
    if ($data === '' || $data === false) {
        if (feof($master) || !isProcAlive($token)) {
            destroyTerminal($token);
            return;
        }
        return;
    }

    // Envia ao cliente conectado (se houver).
    $fd = $GLOBALS['sessions'][$token]['fd'] ?? null;
    if ($fd !== null && $server->isEstablished($fd)) {
        $server->push($fd, $data);
    }

    // Acrescenta ao cache rolante e apara quando exceder o limite.
    $GLOBALS['sessions'][$token]['cache'] .= $data;
    if (strlen($GLOBALS['sessions'][$token]['cache']) > OUTPUT_CACHE_SIZE) {
        $GLOBALS['sessions'][$token]['cache'] =
            substr($GLOBALS['sessions'][$token]['cache'], -OUTPUT_CACHE_SIZE);
    }
}

function isProcAlive(string $token): bool
{
    if (!isset($GLOBALS['sessions'][$token]['proc'])) {
        return false;
    }
    $st = @proc_get_status($GLOBALS['sessions'][$token]['proc']);
    return is_array($st) && !empty($st['running']);
}

// Escreve dados no terminal (entrada do usuário / comandos).
function writeToTerminal(string $token, string $data): void
{
    if (!isset($GLOBALS['sessions'][$token]['master'])) {
        return;
    }
    $master = $GLOBALS['sessions'][$token]['master'];
    $len    = strlen($data);
    $off    = 0;
    while ($off < $len) {
        $n = @fwrite($master, substr($data, $off));
        if ($n === false || $n === 0) {
            break;
        }
        $off += $n;
    }
}

// Ajusta o tamanho da janela do PTY (equivalente ao ptyProcess.resize()).
function applyResize(string $token, int $cols, int $rows): void
{
    if ($cols <= 0 || $rows <= 0 || !isset($GLOBALS['sessions'][$token])) {
        return;
    }
    $GLOBALS['sessions'][$token]['cols'] = $cols;
    $GLOBALS['sessions'][$token]['rows'] = $rows;

    $pts = $GLOBALS['sessions'][$token]['pts'] ?? '';
    if ($pts !== '' && is_writable($pts)) {
        // stty ajusta a winsize do PTY e dispara SIGWINCH ao processo.
        @shell_exec('stty rows ' . (int) $rows . ' cols ' . (int) $cols
            . ' < ' . escapeshellarg($pts) . ' 2>/dev/null');
    }
}

// Encerra explicitamente (closeXtermHandlerCommand).
function closeTerminal(string $token): void
{
    destroyTerminal($token);
    $GLOBALS['terminalIds'] = array_values(array_filter(
        $GLOBALS['terminalIds'],
        static fn($id) => $id !== $token
    ));
    saveTerminalsToFile($GLOBALS['terminalIds']);
    echo "Session for {$token} closed\n";
}

// Libera recursos de um terminal (sem mexer no terminals.json).
function destroyTerminal(string $token): void
{
    if (!isset($GLOBALS['sessions'][$token])) {
        return;
    }
    $s = $GLOBALS['sessions'][$token];

    if (isset($s['master']) && is_resource($s['master'])) {
        @Swoole\Event::del($s['master']);
    }
    if (isset($s['pid']) && $s['pid'] > 0) {
        @posix_kill($s['pid'], SIGKILL);
    }
    if (isset($s['master']) && is_resource($s['master'])) {
        @fclose($s['master']);
    }
    if (isset($s['proc']) && is_resource($s['proc'])) {
        @proc_close($s['proc']);
    }
    unset($GLOBALS['sessions'][$token]);
}

// ---------------------------------------------------------------------
// Servidor WebSocket
// ---------------------------------------------------------------------
$server = new Server(PTY_HOST, PTY_PORT);
$server->set([
    'worker_num'       => 1,   // processo único: todo o estado vive junto
    'log_level'        => 4,
    'daemonize'        => false,
]);

$server->on('start', function () {
    echo 'PTY server listening on ' . PTY_HOST . ':' . PTY_PORT . " (local only)\n";
});

$server->on('open', function (Server $server, Request $request) {
    $uri   = $request->server['request_uri'] ?? '/';
    $token = ltrim($uri, '/');

    $GLOBALS['fdToToken'][$request->fd] = $token;

    if (isset($GLOBALS['sessions'][$token]) && isProcAlive($token)) {
        echo "Resuming session for {$token}\n";
        $GLOBALS['sessions'][$token]['fd'] = $request->fd;
        // Replay do cache para o cliente reconectado ver o contexto.
        if (!empty($GLOBALS['sessions'][$token]['cache'])) {
            $server->push($request->fd, $GLOBALS['sessions'][$token]['cache']);
        }
        return;
    }

    if (isset($GLOBALS['sessions'][$token])) {
        echo "Session for {$token} was dead. Creating a new one.\n";
        destroyTerminal($token);
    }

    if (createNewTerminal($server, $token)) {
        $GLOBALS['sessions'][$token]['fd'] = $request->fd;
    }
});

$server->on('message', function (Server $server, Frame $frame) {
    $token = $GLOBALS['fdToToken'][$frame->fd] ?? null;
    if ($token === null || !isset($GLOBALS['sessions'][$token])) {
        return;
    }

    $cmdStr = $frame->data;
    $trim   = trim($cmdStr);
    echo "Command from {$token}: " . substr($cmdStr, 0, 80) . "\n";

    // Mantém o fd atual associado (reconexões).
    $GLOBALS['sessions'][$token]['fd'] = $frame->fd;

    if ($trim === 'startXtermHandlerCommand') {
        $server->push($frame->fd, $GLOBALS['sessions'][$token]['cache'] ?? '');
        return;
    }

    if ($trim === 'closeXtermHandlerCommand') {
        closeTerminal($token);
        return;
    }

    if ($trim === 'resizeXtermHandlerCommand') {
        $GLOBALS['sessions'][$token]['termcw'] = 2;
        return;
    }

    $termcw = $GLOBALS['sessions'][$token]['termcw'] ?? 0;
    if ($termcw > 0) {
        if ($termcw === 2) {
            $newCols = (int) $trim;
            if ($newCols > 0) {
                applyResize($token, $newCols, $GLOBALS['sessions'][$token]['rows']);
                echo "Resized {$token}: cols={$newCols}\n";
                $GLOBALS['sessions'][$token]['termcw'] = 1;
            }
        } elseif ($termcw === 1) {
            $newRows = (int) $trim;
            if ($newRows > 0) {
                applyResize($token, $GLOBALS['sessions'][$token]['cols'], $newRows);
                echo "Resized {$token}: rows={$newRows}\n";
                $GLOBALS['sessions'][$token]['termcw'] = 0;
            }
        }
        return;
    }

    // Entrada normal: escreve diretamente no terminal.
    writeToTerminal($token, $cmdStr);
});

$server->on('close', function (Server $server, int $fd) {
    $token = $GLOBALS['fdToToken'][$fd] ?? null;
    unset($GLOBALS['fdToToken'][$fd]);
    if ($token !== null && isset($GLOBALS['sessions'][$token])) {
        // NÃO encerra o terminal na desconexão: a sessão sobrevive a
        // reconexões (idêntico ao pty.js).
        if (($GLOBALS['sessions'][$token]['fd'] ?? null) === $fd) {
            $GLOBALS['sessions'][$token]['fd'] = null;
        }
        echo "Client {$token} disconnected (session kept alive)\n";
    }
});

$server->start();
