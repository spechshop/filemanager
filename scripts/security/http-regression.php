<?php
// Run with php -n: these doubles exercise real handlers without starting services.
namespace Swoole\Http {
    class Request {
        public ?array $get = [];
        public ?array $post = [];
        public ?array $files = [];
        public array $header = ['host' => 'localhost'];
        public array $server = ['path_info' => '/', 'request_uri' => '/', 'request_method' => 'GET'];
        public string $body = '';
        public function rawContent(): string { return $this->body; }
    }
    class Response {
        public int $code = 200;
        public array $headers = [];
        public string $body = '';
        public function header($key, $value): void { $this->headers[strtolower($key)] = $value; }
        public function status($code, $reason = ''): void { $this->code = $code; }
        public function end($body = ''): bool { $this->body .= $body; return true; }
        public function write($body): bool { $this->body .= $body; return true; }
        public function sendfile($path): bool { $this->body .= file_get_contents($path); return true; }
    }
}
namespace plugins\Request {
    function file_get_contents($file) { if (isset($GLOBALS['securityReadHook'])) ($GLOBALS['securityReadHook'])($file); return \file_get_contents($file); }
    function exec($command, &$output = [], &$code = 0) { $GLOBALS['effects'][] = $command; $code = 0; return ''; }
    function shell_exec($command) { $GLOBALS['effects'][] = $command; return ''; }
    function proc_open($command, $descriptors, &$pipes, $cwd = null, $env = null) { $GLOBALS['effects'][] = $command; $pipes = []; return false; }
}
namespace {
    if (extension_loaded('swoole')) { fwrite(STDERR, "Use php -n; Swoole doubles must be isolated.\n"); exit(1); }
    $repo = dirname(__DIR__, 2);
    $source = getenv('SECURITY_SOURCE') ?: $repo;
    $tmp = sys_get_temp_dir() . '/fm-security-' . bin2hex(random_bytes(8));
    mkdir($tmp, 0700);
    mkdir($tmp . '/files');
    mkdir($tmp . '/outside');
    chdir($tmp);
    putenv('FILEMANAGER_ALLOWED_ROOTS=' . json_encode([$tmp . '/files']));
    putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
    putenv('FILEMANAGER_ORIGIN=http://localhost');
    $valid = str_repeat('a', 32);
    $other = str_repeat('b', 32);
    $expired = str_repeat('c', 32);
    $tokens = [$valid => ['expire' => time() + 3600], $other => ['expire' => time() + 3600], $expired => ['expire' => time() - 1]];
    file_put_contents($tmp . '/tokens.json', json_encode($tokens));
    $GLOBALS['dataKeys'] = $tokens;
    require $source . '/plugins/Start/server/cache.php';
    require $source . '/plugins/Request/components/appController.php';
    require $source . '/plugins/Request/components/security.php';
    foreach (['securityPolicy', 'safeFiles'] as $component) {
        if (is_file($source . '/plugins/Request/components/' . $component . '.php')) require $source . '/plugins/Request/components/' . $component . '.php';
    }
    require $source . '/plugins/Database/call/call.php';
    foreach (['deleteFile', 'freeRam', 'downloadFile', 'newFile', 'uploadFile', 'renameItem', 'cutCopy', 'deleteMultipleFiles', 'saveTerminal', 'deleteTerminal'] as $handler) {
        require $source . '/plugins/Request/apps/' . $handler . '.php';
    }
    if (is_file($source . '/vendor/autoload.php')) {
        symlink($source . '/vendor', $tmp . '/vendor');
        require_once $source . '/vendor/autoload.php';
        foreach (glob($source . '/plugins/Request/apps/*.php') as $handler) require_once $handler;
    }
    $failed = 0; $count = 0;
    function check(bool $ok, string $name): void {
        global $failed, $count;
        $count++; if (!$ok) $failed++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    }
    function invoke(string $handler, array $query = [], array $data = [], array $headers = [], array $files = []): \Swoole\Http\Response {
        $request = new \Swoole\Http\Request(); $response = new \Swoole\Http\Response();
        $request->get = $query; $request->body = json_encode($data); $request->files = $files;
        if ($handler === 'deleteMultipleFiles') $request->post = $data;
        $request->header = array_merge($request->header, $headers);
        $request->server['path_info'] = '/' . $handler;
        $request->server['request_uri'] = '/' . $handler;
        $GLOBALS['effects'] = [];
        ob_start();
        try { ('\\plugins\\Request\\' . $handler)::api($request, $response); }
        catch (\DomainException $e) { \plugins\Request\security::deny($response); }
        catch (\Throwable $e) { $response->code = 599; $response->body = get_class($e); }
        finally { ob_end_clean(); }
        return $response;
    }
    try {
        foreach (['missing' => null, 'invalid' => 'invalid', 'expired' => $expired, 'revoked' => str_repeat('d', 32)] as $label => $token) {
            file_put_contents($tmp . '/files/keep', 'sentinel');
            $q = ['path' => $tmp . '/files/keep']; if ($token !== null) $q['tokenBrowser'] = $token;
            $r = invoke('deleteFile', $q);
            check($r->code === 401 && file_get_contents($tmp . '/files/keep') === 'sentinel' && !$GLOBALS['effects'], 'delete denied without effects: ' . $label);
            $r = invoke('freeRam', $token === null ? [] : ['tokenBrowser' => $token]);
            check($r->code === 401 && !$GLOBALS['effects'], 'freeRam denied without command: ' . $label);
        }
        if (!getenv('SECURITY_BASELINE_SUBSET')) {
        $r = invoke('deleteFile', ['tokenBrowser' => ['bad'], 'path' => $tmp . '/files/keep']);
        check($r->code === 401 && !$GLOBALS['effects'] && file_exists($tmp . '/files/keep'), 'array credential denied without errors or effects');
        foreach (['https://github.com/org/repo$(touch_INJECTED)', 'https://github.com/org/repo`id`', 'file:///etc/passwd', 'http://127.0.0.1/repo.git', 'ssh://git@github.com/org/repo.git', 'https://github.com.evil.invalid/org/repo', 'https://user@github.com/org/repo', '--upload-pack=touch'] as $url) {
            $r = invoke('importGit', ['tokenBrowser' => $valid, 'url' => $url]);
            check($r->code === 403 && !$GLOBALS['effects'] && !file_exists($tmp . '/files/INJECTED'), 'Git transport/injection denied without launching process');
        }
        // Every discoverable API must deny directly, as well as through the dispatcher.
        $apis = 0;
        foreach (glob($source . '/plugins/Request/apps/*.php') as $handlerFile) {
            $name = basename($handlerFile, '.php'); $class = '\\plugins\\Request\\' . $name;
            if (!class_exists($class) || !method_exists($class, 'api')) continue;
            $apis++;
            $r = invoke($name);
            check($r->code === 401 && !$GLOBALS['effects'], 'direct handler denies before work: ' . $name);
        }
        check($apis === count(array_filter(glob($source . '/plugins/Request/apps/*.php'), fn($path) => str_contains(file_get_contents($path), 'function api('))) && $apis >= 39, 'complete HTTP handler inventory loaded (no silent skips)');
        $r = invoke('downloadFile', ['tokenBrowser' => $other, 'path' => $tmp . '/files/keep'], [], ['authorization' => 'Bearer ' . $valid]);
        check($r->code === 401 && !str_contains($r->body, 'sentinel'), 'conflicting credentials cannot choose another owner');
        file_put_contents('sessions-terminals.json', json_encode([$other => [['id' => 'protected', 'name' => 'PRIVATE-SESSION']]]));
        $before = file_get_contents('sessions-terminals.json');
        $r = invoke('saveTerminal', ['tokenBrowser' => $other], ['id' => 'protected', 'name' => 'evil'], ['authorization' => 'Bearer ' . $valid]);
        check($r->code === 401 && file_get_contents('sessions-terminals.json') === $before, 'cross-owner metadata write leaves session unchanged');
        // A scoped file token cannot turn file access into system administration.
        mkdir($tmp . '/files/a'); mkdir($tmp . '/files/b');
        file_put_contents($tmp . '/files/b/secret', 'TENANT-B');
        $tokens[$valid]['allowedRoots'] = [$tmp . '/files/a'];
        file_put_contents($tmp . '/tokens.json', json_encode($tokens));
        $r = invoke('downloadFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/b/secret']);
        check($r->code === 403 && !str_contains($r->body, 'TENANT-B'), 'scoped token cannot read another workspace');
        $r = invoke('freeRam', ['tokenBrowser' => $valid]);
        check($r->code === 403 && !$GLOBALS['effects'], 'scoped file credential cannot execute system commands');
        $reads = 0;
        $GLOBALS['securityReadHook'] = function ($file) use (&$reads, &$tokens, $valid, $tmp) {
            if ($file !== $tmp . '/tokens.json' || ++$reads !== 2) return;
            unset($tokens[$valid]);
            file_put_contents($file, json_encode($tokens));
            unset($GLOBALS['securityReadHook']);
        };
        $r = invoke('newFile', ['tokenBrowser' => $valid], ['path' => $tmp . '/files/b', 'name' => 'after-revoke', 'type' => 'file', 'content' => 'evil']);
        check(in_array($r->code, [401, 403], true) && !file_exists($tmp . '/files/b/after-revoke') && !$GLOBALS['effects'], 'revocation during policy IO cannot fall back to global filesystem scope');
        unset($GLOBALS['securityReadHook']);
        $tokens[$valid] = ['expire' => time() + 3600];
        unset($tokens[$valid]['allowedRoots']); file_put_contents($tmp . '/tokens.json', json_encode($tokens));
        }
        file_put_contents($tmp . '/outside/private', 'PRIVATE-SENTINEL');
        foreach ([$tmp . '/outside/private', $tmp . '/files/../outside/private', 'php://filter/resource=' . $tmp . '/outside/private'] as $path) {
            $r = invoke('downloadFile', ['tokenBrowser' => $valid, 'path' => $path]);
            check($r->code === 403 && !str_contains($r->body, 'PRIVATE-SENTINEL'), 'download outside denied without disclosure');
            $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $path]);
            check($r->code === 403 && !$GLOBALS['effects'] && file_get_contents($tmp . '/outside/private') === 'PRIVATE-SENTINEL', 'outside deletion denied without effects');
        }
        symlink($tmp . '/outside', $tmp . '/files/link');
        $r = invoke('downloadFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/link/private']);
        check($r->code === 403 && !str_contains($r->body, 'PRIVATE-SENTINEL'), 'symlink escape denied without disclosure');
        $r = invoke('newFile', ['tokenBrowser' => $valid], ['path' => $tmp . '/outside', 'name' => 'injected', 'type' => 'file', 'content' => 'evil']);
        check($r->code === 403 && !file_exists($tmp . '/outside/injected'), 'write outside denied without creation');
        $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/keep'], [], ['origin' => 'https://evil.invalid']);
        check($r->code === 403 && !$GLOBALS['effects'] && file_exists($tmp . '/files/keep'), 'cross-origin deletion denied without effects');
        if (!getenv('SECURITY_BASELINE_SUBSET')) {
        file_put_contents($tmp . '/upload.tmp', 'UPLOAD-SENTINEL');
        foreach (['../outside/uploaded', '/absolute', 'bad' . chr(0) . '.txt'] as $filename) {
            $r = invoke('uploadFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files'], [], [], ['files' => [['name' => $filename, 'tmp_name' => $tmp . '/upload.tmp', 'error' => 0]]]);
            check($r->code === 403 && file_get_contents($tmp . '/upload.tmp') === 'UPLOAD-SENTINEL' && !file_exists($tmp . '/outside/uploaded'), 'invalid multipart filename leaves source/destination intact');
        }
        $r = invoke('uploadFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/outside'], [], [], ['files' => [['name' => 'uploaded', 'tmp_name' => $tmp . '/upload.tmp', 'error' => 0]]]);
        check($r->code === 403 && file_exists($tmp . '/upload.tmp') && !file_exists($tmp . '/outside/uploaded'), 'upload destination outside denied without moving temp file');
        $r = invoke('renameItem', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/keep', 'newName' => $tmp . '/outside/moved']);
        check($r->code === 403 && file_exists($tmp . '/files/keep') && !file_exists($tmp . '/outside/moved'), 'rename escape leaves source intact');
        $r = invoke('cutCopy', ['tokenBrowser' => $valid, 'dest' => $tmp . '/outside'], ['files' => [$tmp . '/files/keep'], 'type' => 'move']);
        check($r->code === 403 && file_exists($tmp . '/files/keep') && !file_exists($tmp . '/outside/keep'), 'move escape leaves source intact');
        $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files']);
        check($r->code === 403 && file_exists($tmp . '/files/keep'), 'workspace root cannot be deleted');
        file_put_contents($tmp . '/files/batch-safe', 'BATCH');
        $r = invoke('deleteMultipleFiles', ['tokenBrowser' => $valid, 'path' => $tmp . '/files'], ['files' => [$tmp . '/files/batch-safe', $tmp . '/outside/private']]);
        check($r->code === 403 && file_exists($tmp . '/files/batch-safe') && !is_dir($tmp . '/files/.trash'), 'late invalid batch item does not create trash or move earlier files');
        $r = invoke('deleteMultipleFiles', ['tokenBrowser' => $valid, 'path' => $tmp . '/files'], ['files' => [$tmp . '/files/batch-safe']]);
        check($r->code === 200 && !file_exists($tmp . '/files/batch-safe') && count(glob($tmp . '/files/.trash/*')) === 1, 'authorized batch deletion at workspace root preserved');
        mkdir($tmp . '/files/recursive'); file_put_contents($tmp . '/files/recursive/first', 'RECURSIVE');
        symlink($tmp . '/outside', $tmp . '/files/recursive/link');
        $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/recursive']);
        check($r->code === 403 && file_get_contents($tmp . '/files/recursive/first') === 'RECURSIVE' && is_link($tmp . '/files/recursive/link'), 'recursive symlink preflight leaves entire directory intact');
        $malicious = "x';touch INJECTED;# $(touch INJECTED2).txt";
        $r = invoke('newFile', ['tokenBrowser' => $valid], ['path' => $tmp . '/files', 'name' => $malicious, 'type' => 'file', 'content' => '<script>alert(1)</script>']);
        check($r->code === 200 && file_exists($tmp . '/files/' . $malicious) && !file_exists('INJECTED') && !file_exists('INJECTED2') && !$GLOBALS['effects'], 'shell metacharacters are literal filename data');
        $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/' . $malicious]);
        check($r->code === 200 && !file_exists($tmp . '/files/' . $malicious) && !file_exists('INJECTED') && !$GLOBALS['effects'], 'authorized deletion uses filesystem without shell');
        $tokens[$valid]['revoked'] = true; file_put_contents($tmp . '/tokens.json', json_encode($tokens));
        $r = invoke('deleteFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/keep']);
        check($r->code === 401 && file_exists($tmp . '/files/keep') && !$GLOBALS['effects'], 'revocation after successful use is observed immediately');
        unset($tokens[$valid]['revoked']); file_put_contents($tmp . '/tokens.json', json_encode($tokens));
        }
        $r = invoke('downloadFile', ['tokenBrowser' => $valid, 'path' => $tmp . '/files/keep']);
        check($r->code === 200 && $r->body === 'sentinel', 'authorized download preserved');
    } finally {
        // No shell; never traverse a symlink while cleaning the private fixture.
        $clean = function ($path) use (&$clean) { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name); rmdir($path); };
        $clean($tmp);
    }
    echo "$count assertions, $failed failures\n";
    exit($failed ? 1 : 0);
}
