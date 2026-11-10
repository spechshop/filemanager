<?php
declare(strict_types=1);
namespace Swoole\Http {
    class Request {
        public array $header = ['host' => 'panel.example'];
        public array $server = ['request_method' => 'GET'];
        public array $get = [];
        public array $post = [];
        public string $body = '';
        public function rawContent(): string { return $this->body; }
    }
    class Response {
        public int $code = 200;
        public array $headers = [];
        public string $body = '';
        public function header($key, $value): void { $this->headers[strtolower($key)] = $value; }
        public function status($code): void { $this->code = $code; }
        public function end($body = ''): bool { $this->body = $body; return true; }
        public function sendfile($file): bool { $this->body = file_get_contents($file); return true; }
    }
}
namespace plugins\Request {
    // Intercept only release metadata and HTTPS downloads; storage and hashing use real files.
    function file_get_contents($filename, ...$arguments) {
        if (str_ends_with($filename, '/scripts/tunnel-cloudflared.json') && isset($GLOBALS['tunnelTestManifest'])) return json_encode($GLOBALS['tunnelTestManifest']);
        if (str_ends_with($filename, '/scripts/tunnel-node.json') && isset($GLOBALS['tunnelTestNodeManifest'])) return json_encode($GLOBALS['tunnelTestNodeManifest']);
        return \file_get_contents($filename, ...$arguments);
    }
    function fopen($filename, ...$arguments) {
        if (str_starts_with($filename, 'https://')) {
            $GLOBALS['tunnelTestRequests'][] = $filename;
            $options = stream_context_get_options($arguments[2]);
            \check($options['ssl']['verify_peer'] && $options['ssl']['verify_peer_name'] && !$options['ssl']['allow_self_signed'], 'Upstream TLS verification disabled');
            \check($options['http']['follow_location'] === 0, 'Unvalidated redirects enabled');
            $reply = array_shift($GLOBALS['tunnelTestReplies']);
            if ($reply === null) throw new \RuntimeException('Unexpected network request');
            if (isset($reply['onOpen'])) ($reply['onOpen'])();
            if ($reply['status'] === 0) return false;
            $stream = \fopen('php://temp', 'w+b');
            fwrite($stream, $reply['body'] ?? ''); rewind($stream);
            $headers = ['HTTP/1.1 ' . $reply['status']];
            if (isset($reply['location'])) $headers[] = 'Location: ' . $reply['location'];
            $GLOBALS['tunnelTestStreams'][(int) $stream] = $headers;
            return $stream;
        }
        return \fopen($filename, ...$arguments);
    }
    function stream_get_meta_data($stream) {
        if (isset($GLOBALS['tunnelTestStreams'][(int) $stream])) return ['wrapper_data' => $GLOBALS['tunnelTestStreams'][(int) $stream]];
        return \stream_get_meta_data($stream);
    }
    function fclose($stream) {
        unset($GLOBALS['tunnelTestStreams'][(int) $stream]);
        return \fclose($stream);
    }
}
namespace {
    use plugins\Request\{privateJson, securityPolicy, tunnelManager, tunnelBinaryCache, fileManagerTunnelAgent, fileManagerSettings};
    use Swoole\Http\{Request, Response};
    $root = dirname(__DIR__, 2);
    foreach (['privateJson', 'securityPolicy', 'security', 'tunnelManager', 'tunnelBinaryCache', 'fileManagerTunnelAgent'] as $name) require $root . '/plugins/Request/components/' . $name . '.php';
    require $root . '/plugins/Request/apps/fileManagerSettings.php';
    $directory = sys_get_temp_dir() . '/fm-tunnels-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    putenv('FILEMANAGER_DATA_DIR=' . $directory);
    putenv('FILEMANAGER_TOKENS_FILE=' . $directory . '/tokens.json');
    putenv('FILEMANAGER_CONFIG_FILE=' . $directory . '/config.json');
    file_put_contents($directory . '/config.json', '{}');
    putenv('FILEMANAGER_ORIGIN=https://panel.example');
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    function denied(callable $fn, string $type): void {
        try { $fn(); } catch (Throwable $e) { if ($e instanceof $type) return; throw $e; }
        throw new RuntimeException('Expected ' . $type);
    }
    function fields(array $extra = []): array { return array_replace(['action' => 'create', 'localHost' => '127.0.0.1', 'localPort' => '8080', 'protocol' => 'http'], $extra); }
    function create(array $extra = []): array {
        $result = tunnelManager::mutate('admin-fixture', fields($extra), 'https://panel.example');
        check(preg_match('~/tunnel/([a-f0-9]{64})~', $result['command'], $match) === 1, 'Capability missing');
        return [$result['id'], $match[1], $result['command']];
    }
    function agentRequest(string $secret, string $method = 'GET', ?array $report = null): Response {
        $request = new Request(); $response = new Response();
        $request->server['request_method'] = $method;
        $request->header['authorization'] = 'Bearer ' . $secret;
        if ($report !== null) $request->body = json_encode($report);
        fileManagerTunnelAgent::api($request, $response);
        return $response;
    }
    function binaryRequest(string $secret, string $session, $asset = 'cloudflared-linux-amd64', bool $bootstrap = false): Response {
        $request = new Request(); $response = new Response();
        $request->header['authorization'] = 'Bearer ' . $secret;
        $request->get = ['asset' => $asset, 'session' => $session];
        fileManagerTunnelAgent::api($request, $response, $bootstrap ? $secret : '');
        return $response;
    }
    try {
        privateJson::update($directory . '/tokens.json', fn() => [
            'admin-fixture' => ['expire' => time() + 3600, 'admin' => true],
            'reader-fixture' => ['expire' => time() + 3600, 'admin' => false],
        ]);
        denied(fn() => tunnelManager::listing('reader-fixture'), DomainException::class);
        denied(fn() => tunnelManager::mutate('reader-fixture', fields(), 'https://panel.example'), DomainException::class);
        foreach (['0', '-1', '65536', '8080;id', ''] as $port) denied(fn() => create(['localPort' => $port]), InvalidArgumentException::class);
        foreach (['http://a:80', 'https://user:pass@localhost', 'https://localhost/path', 'localhost;id', 'file:///etc/passwd', 'localhost?x=1', "host\nfoo"] as $host) denied(fn() => create(['localHost' => $host]), InvalidArgumentException::class);
        denied(fn() => tunnelManager::mutate('admin-fixture', fields(), 'https://panel.example/evil'), InvalidArgumentException::class);
        [$id, $secret, $command] = create(['name' => '<img src=x>', 'localHost' => 'https://[::1]', 'localPort' => '8443', 'noTlsVerify' => 'true']);
        $entry = tunnelManager::listing('admin-fixture')['tunnels'][0];
        check($entry['target'] === 'https://[::1]:8443' && $entry['noTlsVerify'] && $entry['status'] === 'waiting', 'Target or initial state incorrect');
        check($entry['mode'] === 'cloudflare', 'Default mode changed');
        privateJson::update($directory . '/tunnels/registry.json', function ($data) use ($id) { unset($data[$id]['mode'], $data[$id]['remotePort']); return $data; });
        check(tunnelManager::listing('admin-fixture')['tunnels'][0]['mode'] === 'cloudflare', 'Legacy definitions no longer use Cloudflare');
        check(!str_contains($command, "\n") && !str_contains($command, 'admin-fixture') && !str_contains($command, 'sudo'), 'Command is not self-contained and scoped');
        check(str_starts_with($command, 'bash -c ') && str_contains($command, 'curl -fsS ') && !str_contains($command, 'python'), 'Command requires an extra runtime');
        check(str_contains($command, 'resposta vazia') && str_contains($command, 'FILEMANAGER_TUNNEL_AGENT'), 'Bootstrap does not validate its response');
        check(strlen($command) < 4096, 'Generated command exceeds the terminal canonical line limit');
        foreach (['http://10.0.0.10:8080', 'http://193.203.174.212:8080', 'http://197.111.111.200:8080'] as $origin) {
            $generated = tunnelManager::mutate('admin-fixture', ['action' => 'command', 'id' => $id], $origin);
            check(str_contains($generated['command'], $origin . '/tunnel/'), 'Panel address hardcoded');
        }
        $generated = tunnelManager::mutate('admin-fixture', ['action' => 'command', 'id' => $id], 'https://panel.example');
        preg_match('~/tunnel/([a-f0-9]{64})~', $generated['command'], $credential);
        $secret = $credential[1];
        check(!str_contains(file_get_contents($directory . '/tunnels/registry.json'), $secret), 'Credential persisted in cleartext');
        check(!str_contains(json_encode($entry), $secret), 'Listing leaked credential');
        check(agentRequest('admin-fixture')->code === 401, 'Admin key accepted as agent credential');
        check(agentRequest(str_repeat('b', 64))->code === 403, 'Invalid capability accepted');
        $bootstrap = agentRequest($secret);
        check($bootstrap->code === 200 && str_contains($bootstrap->body, 'fm_tunnel_main'), 'Agent bootstrap failed');
        check(str_contains($bootstrap->body, "FM_TARGET='https://[::1]:8443'") && str_contains($bootstrap->body, "FM_ENDPOINT='https://panel.example/fileManagerTunnelAgent'"), 'Remote origin and panel address mixed');
        // Python is now an optional statvfs fallback, not a launch dependency.
        // The Bash agent integration suite runs with python/python3 blocked.
        check(!str_contains($bootstrap->body, 'jq '), 'Remote script requires jq');
        file_put_contents($directory . '/bootstrap.sh', $bootstrap->body);
        $pipes = [];
        $compile = proc_open(['bash', '-n', $directory . '/bootstrap.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) fclose($pipe);
        check(proc_close($compile) === 0, 'Generated Bash agent is invalid');
        check(agentRequest($secret)->code === 409, 'Duplicate connection accepted');
        $registry = privateJson::read($directory . '/tunnels/registry.json');
        $session = $registry[$id]['session'];
        $binary = "fixture official binary\n";
        $GLOBALS['tunnelTestManifest'] = ['version' => '0.0.0', 'assets' => [['name' => 'cloudflared-linux-amd64', 'sha256' => hash('sha256', $binary)]]];
        $GLOBALS['tunnelTestRequests'] = []; $GLOBALS['tunnelTestReplies'] = [];
        check(binaryRequest('', $session)->code === 401, 'Anonymous binary download allowed');
        check(binaryRequest(str_repeat('b', 64), $session)->code === 403, 'Wrong capability downloaded binary');
        check(binaryRequest($secret, str_repeat('c', 48))->code === 403, 'Wrong session downloaded binary');
        check(binaryRequest($secret, $session, '../registry.json')->code === 422, 'Unlisted asset accepted');
        check(binaryRequest($secret, $session, ['cloudflared-linux-amd64'])->code === 422, 'Array asset accepted');
        check(binaryRequest($secret, $session, 'cloudflared-linux-amd64', true)->code === 422, 'Bootstrap URL served binaries');
        check(!$GLOBALS['tunnelTestRequests'], 'Invalid requests reached upstream');
        $GLOBALS['tunnelTestReplies'] = [['status' => 504]];
        $unavailable = binaryRequest($secret, $session);
        check($unavailable->code === 503 && $unavailable->headers['retry-after'] === '5', 'Upstream 504 not retryable');
        check(!glob($directory . '/tunnel-binaries/.cloudflared-*'), 'Failed download left temporary files');
        $GLOBALS['tunnelTestReplies'] = [['status' => 302, 'location' => 'https://release-assets.githubusercontent.com/fixture'], ['status' => 200, 'body' => $binary]];
        $download = binaryRequest($secret, $session);
        check($download->code === 200 && $download->body === $binary && $download->headers['content-type'] === 'application/octet-stream', 'Verified binary not served');
        check(privateJson::read($directory . '/tunnels/registry.json') === $registry, 'Download altered session or lease');
        $count = count($GLOBALS['tunnelTestRequests']);
        check(binaryRequest($secret, $session)->body === $binary && count($GLOBALS['tunnelTestRequests']) === $count, 'Verified cache not reused');
        $cache = $directory . '/tunnel-binaries/0.0.0-cloudflared-linux-amd64';
        check((fileperms($cache) & 0077) === 0, 'Cached binary is not private');
        file_put_contents($cache, 'corrupted cache');
        $GLOBALS['tunnelTestReplies'] = [['status' => 200, 'body' => 'corrupted upstream']];
        check(binaryRequest($secret, $session)->code === 503, 'Corrupted binary served');
        check(!glob($directory . '/tunnel-binaries/.cloudflared-*'), 'Corrupted download left temporary files');
        $GLOBALS['tunnelTestReplies'] = [['status' => 200, 'body' => $binary]];
        check(binaryRequest($secret, $session)->body === $binary, 'Corrupted cache could not recover');
        unlink($cache);
        foreach (['http://github.com/fixture', 'https://evil.example/fixture', 'https://github.com:8443/fixture', 'https://user@github.com/fixture', 'file:///etc/passwd'] as $location) {
            $GLOBALS['tunnelTestReplies'] = [['status' => 302, 'location' => $location]];
            $count = count($GLOBALS['tunnelTestRequests']);
            check(binaryRequest($secret, $session)->code === 503 && count($GLOBALS['tunnelTestRequests']) === $count + 1, 'Unsafe redirect followed: ' . $location);
        }
        $GLOBALS['tunnelTestReplies'] = array_fill(0, 6, ['status' => 302, 'location' => 'https://github.com/loop']);
        check(binaryRequest($secret, $session)->code === 503 && !$GLOBALS['tunnelTestReplies'], 'Redirect limit missing');
        symlink($directory . '/tokens.json', $cache);
        check(binaryRequest($secret, $session)->code === 503, 'Symlink cache served'); unlink($cache);
        $lock = fopen($cache . '.lock', 'c'); flock($lock, LOCK_EX);
        check(binaryRequest($secret, $session)->code === 503, 'Concurrent download blocked heartbeat worker');
        flock($lock, LOCK_UN); fclose($lock);
        $GLOBALS['tunnelTestReplies'] = [['status' => 200, 'body' => $binary, 'onOpen' => function () use ($directory) {
            privateJson::update($directory . '/tokens.json', function ($data) { $data['admin-fixture']['revoked'] = true; return $data; });
        }]];
        check(binaryRequest($secret, $session)->code === 403, 'Access was not rechecked after download');
        privateJson::update($directory . '/tokens.json', function ($data) { $data['admin-fixture']['revoked'] = false; return $data; });
        unset($GLOBALS['tunnelTestManifest']);
        $report = ['session' => $session, 'status' => 'online', 'url' => 'https://fixture-demo.trycloudflare.com'];
        check(agentRequest($secret, 'POST', $report)->code === 200, 'Connection report rejected');
        check(tunnelManager::listing('admin-fixture')['tunnels'][0]['url'] === $report['url'], 'Live link missing');
        check(agentRequest($secret, 'POST', array_replace($report, ['url' => 'javascript:alert(1)']))->code === 422, 'Untrusted URL accepted');
        check(agentRequest($secret, 'POST', array_replace($report, ['url' => 'https://evil.example']))->code === 422, 'Foreign provider accepted');
        privateJson::update($directory . '/tunnels/registry.json', function ($data) use ($id) { $data[$id]['lastSeen'] = time() - 50; return $data; });
        $offline = tunnelManager::listing('admin-fixture')['tunnels'][0];
        check($offline['status'] === 'offline' && $offline['url'] === '', 'Stale connection kept live link');
        check(binaryRequest($secret, $session)->code === 403, 'Stale session downloaded binary');
        $newSession = tunnelManager::agent($secret)['session'];
        check($newSession !== $session && agentRequest($secret, 'POST', $report)->code === 403, 'Replaced process can still report');
        tunnelManager::mutate('admin-fixture', ['action' => 'stop', 'id' => $id], 'https://panel.example');
        check(agentRequest($secret, 'POST', array_replace($report, ['session' => $newSession]))->code === 403, 'Stopped tunnel remained authorized');
        check(binaryRequest($secret, $newSession)->code === 403, 'Stopped tunnel downloaded binary');
        $rotated = tunnelManager::mutate('admin-fixture', ['action' => 'command', 'id' => $id], 'https://panel.example');
        check(agentRequest($secret)->code === 403, 'Previous command remained valid');
        preg_match('~/tunnel/([a-f0-9]{64})~', $rotated['command'], $match);
        $secret = $match[1];
        $session = tunnelManager::agent($secret)['session'];
        privateJson::update($directory . '/tokens.json', function ($data) { $data['admin-fixture']['revoked'] = true; return $data; });
        check(agentRequest($secret, 'POST', array_replace($report, ['session' => $session]))->code === 403, 'Revoked owner left tunnel authorized');
        privateJson::update($directory . '/tokens.json', function ($data) { $data['admin-fixture']['revoked'] = false; return $data; });
        tunnelManager::mutate('admin-fixture', ['action' => 'delete', 'id' => $id], 'https://panel.example');
        check(!tunnelManager::listing('admin-fixture')['tunnels'] && agentRequest($secret)->code === 403, 'Delete did not revoke agent');
        denied(fn() => create(['mode' => 'unknown']), InvalidArgumentException::class);
        foreach (['0', '80', '1023', '65536', '', '18080;id', ['18080']] as $port) {
            denied(fn() => create(['mode' => 'direct', 'remotePort' => $port]), InvalidArgumentException::class);
        }
        [$directId, $directSecret] = create(['mode' => 'direct', 'remotePort' => '18080']);
        denied(fn() => create(['mode' => 'direct', 'remotePort' => '18080']), InvalidArgumentException::class);
        denied(fn() => tunnelManager::mutate('admin-fixture', fields(['mode' => 'direct', 'remotePort' => '8080']), 'https://panel.example:8080'), InvalidArgumentException::class);
        $directConfig = tunnelManager::agent($directSecret);
        check($directConfig['mode'] === 'direct', 'Direct mode not passed to agent');
        $directScript = tunnelManager::script($directConfig);
        check(str_contains($directScript, 'Baixando Node.js privado') && !str_contains($directScript, 'Atualize o Node.js') && !str_contains($directScript, 'cloudflared'), 'Direct installer does not provision its runtime');
        file_put_contents($directory . '/bootstrap.sh', $directScript);
        $compile = proc_open(['bash', '-n', $directory . '/bootstrap.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) fclose($pipe);
        check(proc_close($compile) === 0, 'Generated direct launcher is invalid');
        $directEntry = tunnelManager::authorizeDirect($directSecret, $directConfig['session']);
        $nodeName = 'node-v22.0.0-linux-x64.tar.gz';
        $nodeArchive = "fixture Node archive\n";
        $GLOBALS['tunnelTestNodeManifest'] = ['version' => '22.0.0', 'assets' => ['Linux:x86_64' => ['name' => $nodeName, 'sha256' => hash('sha256', $nodeArchive), 'host' => 'nodejs.org']]];
        $GLOBALS['tunnelTestRequests'] = []; $GLOBALS['tunnelTestReplies'] = [['status' => 200, 'body' => $nodeArchive]];
        $nodeDownload = binaryRequest($directSecret, $directConfig['session'], $nodeName);
        check($nodeDownload->code === 200 && $nodeDownload->body === $nodeArchive, 'Node runtime not served to authorized agent');
        check($GLOBALS['tunnelTestRequests'] === ['https://nodejs.org/dist/v22.0.0/' . $nodeName], 'Node runtime download used unexpected host');
        check(binaryRequest($directSecret, $directConfig['session'], $nodeName)->body === $nodeArchive && count($GLOBALS['tunnelTestRequests']) === 1, 'Node archive cache not reused');
        check(binaryRequest($directSecret, str_repeat('c', 48), $nodeName)->code === 403, 'Wrong session downloaded Node');
        check(binaryRequest($directSecret, $directConfig['session'], 'node-v22.0.0-linux-arm64.tar.gz')->code === 422, 'Unlisted Node archive accepted');
        unlink($directory . '/tunnel-binaries/22.0.0-' . $nodeName);
        $GLOBALS['tunnelTestReplies'] = [['status' => 200, 'body' => 'tampered runtime']];
        check(binaryRequest($directSecret, $directConfig['session'], $nodeName)->code === 503, 'Tampered Node runtime served');
        check(!glob($directory . '/tunnel-binaries/.node-*'), 'Failed Node verification left temporary files');
        $GLOBALS['tunnelTestReplies'] = [['status' => 302, 'location' => 'https://evil.example/' . $nodeName]];
        check(binaryRequest($directSecret, $directConfig['session'], $nodeName)->code === 503 && !$GLOBALS['tunnelTestReplies'], 'Foreign Node redirect followed');
        $GLOBALS['tunnelTestNodeManifest']['assets']['Linux:x86_64']['host'] = 'evil.example';
        check(binaryRequest($directSecret, $directConfig['session'], $nodeName)->code === 503, 'Foreign Node manifest host accepted');
        unset($GLOBALS['tunnelTestNodeManifest']);
        check(tunnelManager::directUrl($directEntry) === 'http://panel.example:18080', 'Direct address is not on the panel host');
        check(tunnelManager::directUrl(array_replace($directEntry, ['origin' => 'https://[::1]:8080', 'target' => 'https://localhost:8443'])) === 'https://[::1]:18080', 'IPv6 direct address invalid');
        $directReport = ['session' => $directConfig['session'], 'status' => 'online', 'url' => 'http://panel.example:18080'];
        check(agentRequest($directSecret, 'POST', $directReport)->code === 200, 'Direct report rejected');
        foreach (['https://fixture.trycloudflare.com', 'http://evil.example:18080', 'http://panel.example:18081', 'javascript:alert(1)'] as $url) {
            check(agentRequest($directSecret, 'POST', array_replace($directReport, ['url' => $url]))->code === 422, 'Untrusted direct URL accepted');
        }
        $directListing = tunnelManager::listing('admin-fixture')['tunnels'][0];
        check($directListing['mode'] === 'direct' && $directListing['remotePort'] === 18080 && $directListing['url'] === $directReport['url'], 'Direct listing incorrect');
        tunnelManager::mutate('admin-fixture', ['action' => 'delete', 'id' => $directId], 'https://panel.example');
        denied(fn() => tunnelManager::authorizeDirect($directSecret, $directConfig['session']), DomainException::class);
        $request = new Request(); $response = new Response();
        $request->header['authorization'] = 'Bearer admin-fixture';
        $request->server['request_method'] = 'POST';
        $request->post = fields() + ['settingGroup' => 'tunnels'];
        fileManagerSettings::api($request, $response);
        check($response->code === 200 && isset(json_decode($response->body, true)['command']), 'Settings API failed');
        echo "PASS tunnels: input validation, scoped credentials, verified binary cache, redirects, download authorization, connection, lease, rotation, stop, revocation, deletion and settings API\n";
    } finally {
        foreach (glob($directory . '/tunnel-binaries/*') ?: [] as $file) unlink($file);
        if (is_dir($directory . '/tunnel-binaries')) rmdir($directory . '/tunnel-binaries');
        foreach (glob($directory . '/tunnels/*') ?: [] as $file) unlink($file);
        if (is_dir($directory . '/tunnels')) rmdir($directory . '/tunnels');
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
        foreach (['FILEMANAGER_DATA_DIR', 'FILEMANAGER_TOKENS_FILE', 'FILEMANAGER_CONFIG_FILE', 'FILEMANAGER_ORIGIN'] as $name) putenv($name);
    }
}
