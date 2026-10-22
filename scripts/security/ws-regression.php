<?php
namespace Swoole {
    class Timer {
        public static array $callbacks = [];
        public static function tick($ms, $callback) { self::$callbacks[] = $callback; return array_key_last(self::$callbacks); }
        public static function clear($id) { unset(self::$callbacks[$id]); }
    }
    class Server {}
}
namespace Swoole\Http { class Request { public array $header = ['host' => 'localhost', 'origin' => 'http://localhost']; public array $server = []; public int $fd = 1; } }
namespace Swoole\WebSocket {
    class Server extends \Swoole\Server {
        public array $closed = []; public array $sent = [];
        public function disconnect($fd, $code = 1000, $reason = '') { $this->closed[$fd] = $code; return true; }
        public function close($fd) { return $this->disconnect($fd); }
        public function isEstablished($fd) { return !isset($this->closed[$fd]); }
        public function exist($fd) { return $this->isEstablished($fd); }
        public function push($fd, $data) { $this->sent[] = [$fd, $data]; return true; }
    }
    class Frame { public int $fd = 1; public string $data = ''; }
}
namespace plugins\Database { class call { public static function data() { if (isset($GLOBALS['securityOnRead'])) { $callback = $GLOBALS['securityOnRead']; unset($GLOBALS['securityOnRead']); $callback(); } return json_decode(file_get_contents(getenv('FILEMANAGER_TOKENS_FILE')), true); } } }
namespace plugins\Request { function file_get_contents($file) { if (isset($GLOBALS['securityOnRead'])) { $callback = $GLOBALS['securityOnRead']; unset($GLOBALS['securityOnRead']); $callback(); } return \file_get_contents($file); } }
namespace plugins\Extension { class utilsFunction { public static function getDiskUsage() { return []; } public static function getMemoryUsage() { return []; } public static function getProcessorName() { return []; } } }
namespace {
    if (extension_loaded('swoole')) { fwrite(STDERR, "Use conventional PHP with -n\n"); exit(1); }
    $root = getenv('SECURITY_SOURCE') ?: dirname(__DIR__, 2);
    require $root . '/plugins/Start/server/cache.php';
    require $root . '/plugins/Request/components/appController.php';
    require (is_file($root . '/plugins/Request/components/securityPolicy.php') ? $root : dirname(__DIR__, 2)) . '/plugins/Request/components/securityPolicy.php';
    require $root . '/plugins/OpenConnection/websocket/OpenConnection.php';
    require $root . '/plugins/Message/server/server.php';
    $file = tempnam(sys_get_temp_dir(), 'fm-ws-');
    putenv('FILEMANAGER_TOKENS_FILE=' . $file); putenv('FILEMANAGER_ORIGIN=http://localhost');
    $a = str_repeat('a', 32); $b = str_repeat('b', 32);
    $tokens = [$a => ['expire' => time() + 3600], $b => ['expire' => time() + 3600], 'hyphen-owner' => ['expire' => time() + 3600], 'expired' => ['expire' => time() - 1], 'revoked' => ['expire' => time() + 3600, 'revoked' => true]];
    file_put_contents($file, json_encode($tokens));
    $count = 0; $fail = 0;
    function check($ok, $name) { global $count, $fail; $count++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
    function openSocket($token, $origin = 'http://localhost') {
        $GLOBALS['websocketConnections'] = $GLOBALS['fdToToken'] = []; \Swoole\Timer::$callbacks = [];
        $request = new \Swoole\Http\Request(); $request->header['origin'] = $origin; $request->server['request_uri'] = '/' . $token;
        $server = new \Swoole\WebSocket\Server(); \plugins\server::open($server, $request); return $server;
    }
    try {
        foreach (['', 'unknown', 'expired', 'revoked'] as $token) {
            $server = openSocket($token);
            check(isset($server->closed[1]) && empty($GLOBALS['websocketConnections']) && empty($GLOBALS['xterm']), 'unauthorized open has no session/process: ' . ($token ?: 'missing'));
        }
        foreach (['https://evil.invalid', 'null', ''] as $origin) {
            $server = openSocket($a . '-terminal', $origin);
            check(isset($server->closed[1]) && empty($GLOBALS['websocketConnections']), 'invalid/missing origin has no session');
        }
        $server = openSocket($a . '-terminal');
        check(!isset($server->closed[1]) && $GLOBALS['websocketConnections'][1]['clientToken'] === $a, 'connection binds authenticated owner');
        $hyphen = openSocket('hyphen-owner-terminal');
        check(!isset($hyphen->closed[1]) && ($GLOBALS['websocketConnections'][1]['clientToken'] ?? '') === 'hyphen-owner', 'hyphenated token binds complete owner');
        $server = openSocket($a . '-terminal');
        $GLOBALS['xterm'][$b . '-terminal'] = ['fd' => 99, 'marker' => 'protected'];
        $before = $GLOBALS['xterm'];
        $frame = new \Swoole\WebSocket\Frame(); $frame->data = json_encode(['token' => $b . '-terminal', 'isCodex' => true, 'command' => 'touch unauthorized']);
        \plugins\server::message($server, $frame);
        check(isset($server->closed[1]) && $before === $GLOBALS['xterm'] && !$server->sent, 'payload cannot steal another owner/session or send terminal input');
        unset($GLOBALS['xterm']);
        $server = openSocket($a . '-terminal');
        $tokens[$a]['revoked'] = true; file_put_contents($file, json_encode($tokens));
        $frame->data = json_encode(['token' => $a . '-terminal', 'isCodex' => true, 'command' => 'touch unauthorized']);
        \plugins\server::message($server, $frame);
        check(isset($server->closed[1]) && empty($GLOBALS['xterm']) && !$server->sent, 'revoked active session cannot create terminal/send command');
        $server = openSocket($a . '-terminal');
        check(isset($server->closed[1]) && empty($GLOBALS['websocketConnections']), 'revoked reconnect has no replay or process');
        unset($tokens[$a]['revoked']); file_put_contents($file, json_encode($tokens));
        $server = openSocket($a . '-terminal');
        $tokens[$a]['expire'] = time() - 1; file_put_contents($file, json_encode($tokens));
        foreach (\Swoole\Timer::$callbacks as $callback) $callback();
        check(isset($server->closed[1]) && empty($GLOBALS['websocketConnections']) && !\Swoole\Timer::$callbacks, 'idle expiry actively disconnects and removes timer/binding');
        $tokens[$a]['expire'] = time() + 3600; file_put_contents($file, json_encode($tokens));
        $server = openSocket($a . '-terminal');
        $replacement = ['token' => $b . '-terminal', 'clientToken' => $b, 'identity' => 'replacement-binding'];
        $GLOBALS['securityOnRead'] = function () use ($replacement, $b) {
            $GLOBALS['websocketConnections'][1] = $replacement;
            $GLOBALS['fdToToken'][1] = $b . '-terminal';
        };
        $frame->data = json_encode(['token' => $a . '-terminal', 'isCodex' => true, 'command' => 'touch unauthorized']);
        \plugins\server::message($server, $frame);
        check(!$server->closed && empty($GLOBALS['xterm']) && ($GLOBALS['websocketConnections'][1] ?? []) === $replacement, 'fd reused during credential IO cannot mutate or disconnect replacement session');
        unset($GLOBALS['securityOnRead']);
        // An fd reused after disconnect must never inherit the old credential.
        $server = openSocket($b . '-terminal');
        $frame->data = json_encode(['token' => $a . '-terminal', 'isCodex' => true, 'command' => 'touch unauthorized']);
        \plugins\server::message($server, $frame);
        check(isset($server->closed[1]) && empty($GLOBALS['xterm']), 'reused connection cannot inherit prior owner');
    } finally { unlink($file); }
    echo "$count assertions, $fail failures\n"; exit($fail ? 1 : 0);
}
