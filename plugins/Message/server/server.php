<?php
declare(strict_types=1);

namespace plugins;

use Exception;
use plugins\Extension\utilsFunction;
use plugins\Request\appController;
use plugins\Start\cache;
use plugins\websocket\OpenConnection;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

error_reporting(E_ALL);

class server extends OpenConnection
{
    private static array $metricsPending = [];
    private static array $metricsNextAt = [];
    private static array $bridgeClients = [];

    public static function stopWorker(): void
    {
        self::$metricsPending = self::$metricsNextAt = [];
        foreach ($GLOBALS['searchInFileJobs'] ?? [] as $fd => $_) {
            $GLOBALS['searchInFileJobs'][$fd]['cancelled'] = true;
            $GLOBALS['searchInFileJobs'][$fd]['paused'] = false;
        }
        foreach ($GLOBALS['xterm'] ?? [] as $terminal) {
            if (isset($terminal['heartbeat'])) Coroutine::cancel($terminal['heartbeat']);
            if (($terminal['wsClient'] ?? null) instanceof Client) $terminal['wsClient']->close();
        }
        foreach (['codexAgent', 'lsp'] as $service) {
            foreach ($GLOBALS[$service] ?? [] as $session) {
                if (($session['chan'] ?? null) instanceof \Swoole\Coroutine\Channel) $session['chan']->close();
            }
        }
        foreach (self::$bridgeClients as $client) $client->close();
    }

    public static function close(\Swoole\Server $server, int $fd): void
    {
        unset(self::$metricsPending[$fd], self::$metricsNextAt[$fd]);
        unset($GLOBALS['fdToToken'][$fd], $GLOBALS['websocketConnections'][$fd]);

        if (!empty($GLOBALS['searchInFileJobs'][$fd])) {
            $GLOBALS['searchInFileJobs'][$fd]['cancelled'] = true;
            $GLOBALS['searchInFileJobs'][$fd]['paused'] = false;
        }

        if (!empty($GLOBALS['coroutinesProcess'])) {
            foreach ($GLOBALS['coroutinesProcess'] as $token => $process) {
                if (($process['fd'] ?? null) === $fd) {
                    $GLOBALS['coroutinesProcess'][$token]['fd'] = null;
                }
            }
        }

        if (!empty($GLOBALS['xterm'])) {
            foreach ($GLOBALS['xterm'] as $token => $terminal) {
                if (($terminal['fd'] ?? null) === $fd) {
                    $GLOBALS['xterm'][$token]['fd'] = null;
                }
            }
        }

        if (!empty($GLOBALS['lsp'])) {
            foreach ($GLOBALS['lsp'] as $token => $session) {
                if (($session['fd'] ?? null) === $fd) {
                    $GLOBALS['lsp'][$token]['fd'] = null;
                }
            }
        }

        if (!empty($GLOBALS['codexAgent'])) {
            foreach ($GLOBALS['codexAgent'] as $token => $session) {
                if (($session['fd'] ?? null) === $fd) {
                    $GLOBALS['codexAgent'][$token]['fd'] = null;
                }
            }
        }
    }

    public static function message(\Swoole\Server $server, Frame $frame)
    {
        $GLOBALS['dataKeys'] = \plugins\Database\call::data() ?? [];
        $object = json_decode($frame->data, true);
        if (empty($object)) {
            return $server->close($frame->fd);
        }
        $socketToken = self::socketTokenForFd($frame->fd);
        $tokenBrowser = !empty($object["token"]) && is_string($object["token"])
            ? $object["token"]
            : $socketToken;
        if (array_key_exists('codexAgent', $object) && str_ends_with($tokenBrowser, '-codex-agent')) {
            $tokenClient = substr($tokenBrowser, 0, -strlen('-codex-agent'));
        } elseif ($tokenBrowser !== '' && str_contains($tokenBrowser, '-')) {
            $tokenClient = explode("-", $tokenBrowser)[0];
        } else {
            $tokenClient = $tokenBrowser;
        }
        if ($tokenBrowser === '' && $socketToken !== '') {
            $tokenBrowser = $socketToken;
        }
        if ($tokenClient === '' && $socketToken !== '') {
            $tokenClient = self::clientTokenFromSocketToken($socketToken);
        }
        if (!empty($GLOBALS["coroutinesProcess"][$tokenBrowser])) {
            $GLOBALS["coroutinesProcess"][$tokenBrowser]["fd"] = $frame->fd;
        }

        if (array_key_exists("codexAgent", $object)) {
            if (!self::validBrowserToken($tokenClient)) {
                return $server->close($frame->fd);
            }

            $payload = $object["payload"] ?? null;
            if (is_array($payload)) {
                $payload = self::withCodexPreferences($payload, $tokenClient);
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_string($payload)) {
                $decodedPayload = json_decode($payload, true);
                if (is_array($decodedPayload)) {
                    $payload = json_encode(
                        self::withCodexPreferences($decodedPayload, $tokenClient),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
            }
            if (!is_string($payload) || $payload === '' || strlen($payload) > 131072) {
                self::pushCodexAgentError($server, $frame->fd, 'Mensagem inválida ou muito grande.');
                return;
            }

            $GLOBALS["codexAgent"][$tokenBrowser]["fd"] = $frame->fd;
            if (empty($GLOBALS["codexAgent"][$tokenBrowser]["chan"])) {
                $GLOBALS["codexAgent"][$tokenBrowser]["chan"] = new \Swoole\Coroutine\Channel(1024);
            }
            $channel = $GLOBALS["codexAgent"][$tokenBrowser]["chan"];
            if (empty($GLOBALS["codexAgent"][$tokenBrowser]["worker"])) {
                $GLOBALS["codexAgent"][$tokenBrowser]["worker"] = true;
                Coroutine::create(function () use ($tokenBrowser, $server) {
                    self::runCodexAgentWorker($tokenBrowser, $server);
                    $GLOBALS["codexAgent"][$tokenBrowser]["worker"] = false;
                });
            }
            $channel->push($payload);
            return;
        }

        if (array_key_exists("isCodex", $object)) {
            if (!key_exists($tokenClient, cache::global()["dataKeys"] ?? [])) {
                return $server->close($frame->fd);
            }

            if (!empty($tokenBrowser)) {
                $GLOBALS["xterm"][$tokenBrowser]["fd"] = $frame->fd;

                // Conecta no PTY de forma síncrona nesta corrotina para o push
                // logo abaixo já ter wsClient pronto (evita race "foi fechado").
                $wsClient = $GLOBALS["xterm"][$tokenBrowser]["wsClient"] ?? null;
                if (!($wsClient instanceof Client) || !$wsClient->connected) {
                    try {
                        $wsClient = self::getWsClient($tokenBrowser);
                    } catch (Throwable) {
                        print $tokenBrowser . " foi fechado" . PHP_EOL;
                        $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = false;
                        return $server->close($frame->fd);
                    }
                }

                if (empty($GLOBALS["xterm"][$tokenBrowser]["relay"])) {
                    $GLOBALS["xterm"][$tokenBrowser]["relay"] = true;
                    Coroutine::create(function () use ($tokenBrowser, $server, $wsClient) {
                        try {
                            while (true) {
                                $message = $wsClient->recv();
                                if ($message === false) {
                                    print $tokenBrowser . " false agora" . PHP_EOL;
                                    break;
                                }
                                // recv() devolve "" em timeout — não derruba o PTY.
                                if ($message === '' || $message === null) {
                                    if (!$wsClient->connected) {
                                        print $tokenBrowser . " false agora" . PHP_EOL;
                                        break;
                                    }
                                    continue;
                                }
                                if (!is_object($message) || $message->data === null) {
                                    print $tokenBrowser . " false agora" . PHP_EOL;
                                    break;
                                }

                                $fd = $GLOBALS["xterm"][$tokenBrowser]["fd"] ?? null;
                                if (!is_int($fd) || !$server->exist($fd)) {
                                    // Browser saiu temporariamente; mantém a sessão PTY.
                                    continue;
                                }

                                $server->push($fd, (string) $message->data);
                            }
                        } finally {
                            $GLOBALS["xterm"][$tokenBrowser]["relay"] = false;
                            if (($GLOBALS["xterm"][$tokenBrowser]["wsClient"] ?? null) === $wsClient) {
                                $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = false;
                            }
                            try {
                                if ($wsClient->connected) {
                                    $wsClient->close();
                                }
                            } catch (Throwable) {
                            }
                        }
                    });
                }

                $wsClient = $GLOBALS["xterm"][$tokenBrowser]["wsClient"] ?? null;
                if (!($wsClient instanceof Client) || !$wsClient->connected) {
                    print $tokenBrowser . " foi fechado" . PHP_EOL;
                    return $server->close($frame->fd);
                }

                if (empty($object["command"])) {
                    if (!empty($object["dirCurrent"])) {
                        $dirCurrent = appController::baseDir() . "files" . $object["dirCurrent"];
                        try {
                            $wsClient->push('cd "' . $dirCurrent . '"' . PHP_EOL);
                        } catch (Exception $e) {
                        }
                    } else {
                        $wsClient->push("0");
                    }
                } else {
                    try {
                        $wsClient->push("{$object["command"]}");
                        if ($object['command'] == 'resizeXtermHandlerCommand') {
                            $wsClient->push("{$object["cols"]}");
                            $wsClient->push("{$object["rows"]}");
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        } elseif (array_key_exists("lsp", $object)) {
            // -------------------------------------------------------------
            // Relay do Language Server (Intelephense) rodando em 127.0.0.1:3057.
            // Substitui a antiga fonte estática (stubs-generated.json): o
            // editor fala LSP de verdade através deste proxy seguro.
            // -------------------------------------------------------------
            if (!key_exists($tokenClient, cache::global()["dataKeys"])) {
                return $server->close($frame->fd);
            }

            $GLOBALS["lsp"][$tokenBrowser]["fd"] = $frame->fd;

            // Canal de saída (browser -> language server). Criado de forma
            // síncrona para nunca perdermos o "initialize" inicial.
            if (empty($GLOBALS["lsp"][$tokenBrowser]["chan"])) {
                $GLOBALS["lsp"][$tokenBrowser]["chan"] = new \Swoole\Coroutine\Channel(2048);
            }

            $channel = $GLOBALS["lsp"][$tokenBrowser]["chan"];
            if (empty($GLOBALS["lsp"][$tokenBrowser]["worker"])) {
                $GLOBALS["lsp"][$tokenBrowser]["worker"] = true;
                Coroutine::create(function () use (&$tokenBrowser, $server) {
                    self::runLspWorker($tokenBrowser, $server);
                    $GLOBALS["lsp"][$tokenBrowser]["worker"] = false;
                });
            }

            if (isset($object["payload"]) && $object["payload"] !== "") {
                $channel->push($object["payload"]);
            }
            return;
        } elseif (array_key_exists("searchInFile", $object)) {
            if (!self::validBrowserToken($tokenClient)) {
                return $server->close($frame->fd);
            }

            self::handleSearchMessage($server, $frame->fd, $object, $socketToken, $tokenClient);
            return;
        } else {
            // Métricas da máquina: exige token válido (pode vir no path ou no payload).
            if (!self::validBrowserToken($tokenClient)) {
                return $server->close($frame->fd);
            }
            self::sendMetrics($server, $frame->fd);
        }
    }

    private static function sendMetrics(\Swoole\Server $server, int $fd): void
    {
        if (isset(self::$metricsPending[$fd]) || !$server->isEstablished($fd)) {
            return;
        }
        // Older panels request again on every reply. Bound that feedback loop
        // on the server too, with at most one suspended request per connection.
        $pending = new \stdClass();
        self::$metricsPending[$fd] = $pending;
        try {
            $delay = (self::$metricsNextAt[$fd] ?? 0.0) - hrtime(true) / 1e9;
            if ($delay > 0) {
                Coroutine::sleep($delay);
            }
            // A disconnected fd may have been reused while this coroutine slept.
            if ((self::$metricsPending[$fd] ?? null) !== $pending || !$server->isEstablished($fd)) {
                return;
            }
            self::$metricsNextAt[$fd] = hrtime(true) / 1e9 + 1.0;
            $server->push(
                $fd,
                json_encode([
                    "success" => true,
                    "disk" => utilsFunction::getDiskUsage(),
                    "memory" => utilsFunction::getMemoryUsage(),
                    "cpu" => utilsFunction::getProcessorName(),
                ])
            );
        } finally {
            if ((self::$metricsPending[$fd] ?? null) === $pending) {
                unset(self::$metricsPending[$fd]);
            }
        }
    }

    private static function validBrowserToken(string $token): bool
    {
        $dataKeys = cache::global()["dataKeys"] ?? [];
        if ($token === '' || !key_exists($token, $dataKeys)) {
            return false;
        }
        $expires = $dataKeys[$token]["expire"] ?? 0;
        return time() < (int) $expires;
    }

    private static function pushCodexAgentError(\Swoole\Server $server, int $fd, string $message): void
    {
        if (!$server->exist($fd)) {
            return;
        }
        $server->push($fd, json_encode([
            'codexAgent' => true,
            'payload' => [
                'type' => 'status',
                'status' => 'error',
                'message' => $message,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function socketTokenForFd(int $fd): string
    {
        return (string) ($GLOBALS['websocketConnections'][$fd]['token'] ?? $GLOBALS['fdToToken'][$fd] ?? '');
    }

    private static function pushSearchEvent(\Swoole\Server $server, int $fd, array $payload): void
    {
        if (!$server->exist($fd)) {
            return;
        }

        $server->push($fd, json_encode([
            'searchInFile' => true,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizeSearchPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return appController::baseDir();
        }

        $normalized = is_file($path) ? dirname($path) : $path;
        $normalized = str_replace('//', '/', $normalized);

        return rtrim($normalized, '/') ?: '/';
    }

    private static function handleSearchMessage(\Swoole\Server $server, int $fd, array $object, string $socketToken, string $clientToken): void
    {
        if (!isset($GLOBALS['searchInFileJobs'])) {
            $GLOBALS['searchInFileJobs'] = [];
        }

        $action = strtolower(trim((string) ($object['action'] ?? 'start')));
        $job =& $GLOBALS['searchInFileJobs'][$fd];

        if ($action === 'cancel') {
            if (!empty($job)) {
                $job['cancelled'] = true;
                $job['paused'] = false;
                $job['status'] = 'cancelled';
            }
            self::pushSearchEvent($server, $fd, [
                'type' => 'status',
                'status' => 'cancelled',
                'message' => 'Busca cancelada.',
            ]);
            return;
        }

        if ($action === 'pause') {
            if (!empty($job) && ($job['status'] ?? '') === 'running') {
                $job['paused'] = true;
                $job['status'] = 'paused';
                self::pushSearchEvent($server, $fd, [
                    'type' => 'status',
                    'status' => 'paused',
                    'message' => 'Busca pausada.',
                    'found' => (int) ($job['found'] ?? 0),
                    'scanned' => (int) ($job['scanned'] ?? 0),
                ]);
            }
            return;
        }

        if ($action === 'resume') {
            if (!empty($job) && ($job['status'] ?? '') === 'paused') {
                $job['paused'] = false;
                $job['status'] = 'running';
                self::pushSearchEvent($server, $fd, [
                    'type' => 'status',
                    'status' => 'running',
                    'message' => 'Busca retomada.',
                    'found' => (int) ($job['found'] ?? 0),
                    'scanned' => (int) ($job['scanned'] ?? 0),
                ]);
            }
            return;
        }

        $search = trim((string) ($object['search'] ?? ''));
        if ($search === '') {
            self::pushSearchEvent($server, $fd, [
                'type' => 'status',
                'status' => 'error',
                'message' => 'O texto de busca não pode ficar vazio.',
            ]);
            return;
        }

        $path = self::normalizeSearchPath((string) ($object['path'] ?? appController::baseDir()));
        $limit = max(1, (int) ($object['limit'] ?? 1000));

        if (!empty($job)) {
            $job['cancelled'] = true;
            $job['paused'] = false;
        }

        $jobId = bin2hex(random_bytes(8));
        $job = [
            'fd' => $fd,
            'id' => $jobId,
            'socketToken' => $socketToken,
            'clientToken' => $clientToken,
            'search' => $search,
            'path' => $path,
            'limit' => $limit,
            'status' => 'running',
            'paused' => false,
            'cancelled' => false,
            'pauseNotified' => false,
            'found' => 0,
            'scanned' => 0,
            'startedAt' => time(),
        ];

        self::pushSearchEvent($server, $fd, [
            'type' => 'status',
            'status' => 'running',
            'message' => 'Busca iniciada.',
            'path' => $path,
            'search' => $search,
            'limit' => $limit,
        ]);

        Coroutine::create(function () use ($server, $fd, $jobId) {
            self::runSearchJob($server, $fd, $jobId);
        });
    }

    private static function runSearchJob(\Swoole\Server $server, int $fd, string $jobId): void
    {
        if (empty($GLOBALS['searchInFileJobs'][$fd])) {
            return;
        }

        $job =& $GLOBALS['searchInFileJobs'][$fd];
        if (($job['id'] ?? null) !== $jobId) {
            return;
        }
        $path = $job['path'];
        $needle = $job['search'];
        $limit = (int) $job['limit'];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $path,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\UnexpectedValueException) {
            self::pushSearchEvent($server, $fd, [
                'type' => 'status',
                'status' => 'error',
                'message' => 'Não foi possível abrir o diretório informado.',
            ]);
            unset($GLOBALS['searchInFileJobs'][$fd]);
            return;
        }

        $batch = 0;
        foreach ($iterator as $item) {
            if (!isset($GLOBALS['searchInFileJobs'][$fd])) {
                return;
            }
            if (($job['id'] ?? null) !== $jobId) {
                return;
            }

            if (!empty($job['cancelled'])) {
                self::pushSearchEvent($server, $fd, [
                    'type' => 'status',
                    'status' => 'cancelled',
                    'message' => 'Busca cancelada.',
                    'found' => (int) $job['found'],
                    'scanned' => (int) $job['scanned'],
                ]);
                unset($GLOBALS['searchInFileJobs'][$fd]);
                return;
            }

            while (!empty($job['paused'])) {
                if (($job['id'] ?? null) !== $jobId) {
                    return;
                }
                if (!empty($job['cancelled'])) {
                    break;
                }
                if (empty($job['pauseNotified'])) {
                    $job['pauseNotified'] = true;
                    self::pushSearchEvent($server, $fd, [
                        'type' => 'status',
                        'status' => 'paused',
                        'message' => 'Busca pausada.',
                        'found' => (int) $job['found'],
                        'scanned' => (int) $job['scanned'],
                    ]);
                }
                Coroutine::sleep(0.25);
            }

            if (!empty($job['cancelled'])) {
                continue;
            }
            if (($job['id'] ?? null) !== $jobId) {
                return;
            }

            if (!empty($job['pauseNotified'])) {
                $job['pauseNotified'] = false;
                self::pushSearchEvent($server, $fd, [
                    'type' => 'status',
                    'status' => 'running',
                    'message' => 'Busca retomada.',
                    'found' => (int) $job['found'],
                    'scanned' => (int) $job['scanned'],
                ]);
            }

            if (!$item->isFile()) {
                $batch++;
                if ($batch % 40 === 0) {
                    Coroutine::sleep(0.02);
                }
                continue;
            }

            $pathFile = $item->getPathname();
            $job['scanned']++;

            if (\plugins\Request\searchInFile::fileContainsString($pathFile, $needle)) {
                $job['found']++;
                $size = @filesize($pathFile);
                self::pushSearchEvent($server, $fd, [
                    'type' => 'result',
                    'item' => [
                        'path' => $pathFile,
                        'sizeBytes' => $size === false ? null : $size,
                        'sizeLabel' => $size === false ? 'N/A' : utilsFunction::formatBytes($size),
                    ],
                    'found' => (int) $job['found'],
                    'scanned' => (int) $job['scanned'],
                ]);
                if ($job['found'] >= $limit) {
                    self::pushSearchEvent($server, $fd, [
                        'type' => 'status',
                        'status' => 'complete',
                        'message' => 'Limite de resultados atingido.',
                        'found' => (int) $job['found'],
                        'scanned' => (int) $job['scanned'],
                    ]);
                    unset($GLOBALS['searchInFileJobs'][$fd]);
                    return;
                }
            }

            if ($job['scanned'] % 25 === 0) {
                self::pushSearchEvent($server, $fd, [
                    'type' => 'progress',
                    'found' => (int) $job['found'],
                    'scanned' => (int) $job['scanned'],
                    'path' => $path,
                ]);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                Coroutine::sleep(0.02);
            }
        }

        self::pushSearchEvent($server, $fd, [
            'type' => 'status',
            'status' => 'complete',
            'message' => 'Busca concluída.',
            'found' => (int) $job['found'],
            'scanned' => (int) $job['scanned'],
        ]);
        unset($GLOBALS['searchInFileJobs'][$fd]);
    }

    /**
     * Injeta no bridge somente as preferências associadas ao token autenticado.
     * O navegador não pode escolher configurações pertencentes a outro token.
     */
    private static function withCodexPreferences(array $payload, string $token): array
    {
        $actions = ['thread.start', 'thread.resume', 'thread.settings.update', 'turn.start'];
        if (!in_array($payload['action'] ?? null, $actions, true)) {
            return $payload;
        }

        unset($payload['model'], $payload['reasoningEffort']);
        try {
            $preferences = \plugins\Request\fileManagerConfig::codexPreferences(
                \plugins\Request\fileManagerConfig::read(),
                $token
            );
        } catch (Throwable) {
            return $payload;
        }

        if (!empty($preferences['model'])) {
            $payload['model'] = $preferences['model'];
        }
        if (!empty($preferences['reasoningEffort'])) {
            $payload['reasoningEffort'] = $preferences['reasoningEffort'];
        }
        return $payload;
    }

    /**
     * Mantém o bridge oficial do Codex isolado em loopback. O navegador fala
     * somente com este servidor Swoole e nunca recebe CODEX_ACCESS_TOKEN.
     */
    private static function runCodexAgentWorker(string $tokenBrowser, \Swoole\Server $server): void
    {
        self::runBridgeWorker('codexAgent', 3091, '/agent', $tokenBrowser, $server);
    }

    public static function runLspWorker(string $tokenBrowser, \Swoole\Server $server): void
    {
        self::runBridgeWorker('lsp', 3057, '/' . $tokenBrowser, $tokenBrowser, $server);
    }

    private static function runBridgeWorker(string $service, int $port, string $path, string $token, \Swoole\Server $server): void
    {
        $channel = $GLOBALS[$service][$token]['chan'];
        $client = new Client('127.0.0.1', $port);
        self::$bridgeClients[spl_object_id($client)] = $client;
        $client->set(['websocket_mask' => true, 'connect_timeout' => 2, 'timeout' => 2]);
        try {
            if (!$client->upgrade($path)) {
                if ($service === 'codexAgent') {
                    $fd = $GLOBALS[$service][$token]['fd'] ?? null;
                    if (is_int($fd) && $server->isEstablished($fd)) {
                        self::pushCodexAgentError($server, $fd, 'O serviço Codex Agent não está disponível.');
                    }
                }
                return;
            }
            // Bound an unresponsive HTTP upgrade; established streams may idle.
            $client->set(['timeout' => -1]);
            Coroutine::create(static function () use ($client, $channel, $service, $token, $server): void {
                try {
                    while ($client->connected) {
                        $message = $client->recv(-1);
                        if ($message === false || $message === '' || !is_object($message)
                            || $message->opcode === WEBSOCKET_OPCODE_CLOSE) {
                            break;
                        }
                        if ($message->data === '') continue;
                        $payload = $service === 'codexAgent' ? json_decode($message->data, true) : $message->data;
                        if ($service === 'codexAgent' && !is_array($payload)) continue;
                        $fd = $GLOBALS[$service][$token]['fd'] ?? null;
                        if (is_int($fd) && $server->isEstablished($fd)) {
                            $server->push($fd, json_encode([$service => true, 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    }
                } finally {
                    // EOF must also wake the writer blocked in Channel::pop().
                    // Otherwise the dead worker prevents every future reconnect.
                    $channel->close();
                }
            });
            while ($client->connected && ($payload = $channel->pop()) !== false) {
                if (!is_string($payload) || !$client->push($payload)) break;
            }
        } catch (Throwable) {
            // The next browser message may start a fresh bridge connection.
        } finally {
            $channel->close();
            $client->close();
            unset(self::$bridgeClients[spl_object_id($client)]);
            if (($GLOBALS[$service][$token]['chan'] ?? null) === $channel) {
                $GLOBALS[$service][$token]['chan'] = null;
            }
        }
    }

    public static function getWsClient(mixed $tokenBrowser): Client
    {
        $wsClient = new Client("127.0.0.1", 6060);
        // Cliente WS deve mascarar frames (RFC 6455); timeout alto evita
        // cortar sessões ociosas do terminal entre teclas.
        $wsClient->set([
            'websocket_mask' => true,
            'timeout' => 60,
        ]);
        if (!$wsClient->upgrade("/$tokenBrowser")) {
            throw new Exception('Falha ao conectar no PTY local em 127.0.0.1:6060');
        }
        $GLOBALS["xterm"][$tokenBrowser]["wsClient"] = $wsClient;

        $heartbeat = Coroutine::create(function () use ($wsClient, $tokenBrowser) {
            try {
                while (true) {
                    Coroutine::sleep(30);
                    if (!$wsClient->connected || !$wsClient->push("", WEBSOCKET_OPCODE_PING)) break;
                }
            } finally {
                if (($GLOBALS['xterm'][$tokenBrowser]['heartbeat'] ?? null) === Coroutine::getCid()) {
                    unset($GLOBALS['xterm'][$tokenBrowser]['heartbeat']);
                }
            }
        });
        $GLOBALS['xterm'][$tokenBrowser]['heartbeat'] = $heartbeat;
        return $wsClient;
    }
}
