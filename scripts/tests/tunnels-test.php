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
    }
}
namespace {
    use plugins\Request\{privateJson, securityPolicy, tunnelManager, fileManagerTunnelAgent, fileManagerSettings};
    use Swoole\Http\{Request, Response};
    $root = dirname(__DIR__, 2);
    foreach (['privateJson', 'securityPolicy', 'security', 'tunnelManager', 'fileManagerTunnelAgent'] as $name) require $root . '/plugins/Request/components/' . $name . '.php';
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
        check(!str_contains($command, "\n") && !str_contains($command, 'admin-fixture') && !str_contains($command, 'sudo'), 'Command is not self-contained and scoped');
        check(str_starts_with($command, 'curl -fsS ') && str_ends_with($command, ' | bash') && !str_contains($command, 'python'), 'Command requires an extra runtime');
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
        check(!str_contains($bootstrap->body, 'python') && !str_contains($bootstrap->body, 'jq '), 'Remote script requires another runtime');
        file_put_contents($directory . '/bootstrap.sh', $bootstrap->body);
        $pipes = [];
        $compile = proc_open(['bash', '-n', $directory . '/bootstrap.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) fclose($pipe);
        check(proc_close($compile) === 0, 'Generated Bash agent is invalid');
        check(agentRequest($secret)->code === 409, 'Duplicate connection accepted');
        $registry = privateJson::read($directory . '/tunnels/registry.json');
        $session = $registry[$id]['session'];
        $report = ['session' => $session, 'status' => 'online', 'url' => 'https://fixture-demo.trycloudflare.com'];
        check(agentRequest($secret, 'POST', $report)->code === 200, 'Connection report rejected');
        check(tunnelManager::listing('admin-fixture')['tunnels'][0]['url'] === $report['url'], 'Live link missing');
        check(agentRequest($secret, 'POST', array_replace($report, ['url' => 'javascript:alert(1)']))->code === 422, 'Untrusted URL accepted');
        check(agentRequest($secret, 'POST', array_replace($report, ['url' => 'https://evil.example']))->code === 422, 'Foreign provider accepted');
        privateJson::update($directory . '/tunnels/registry.json', function ($data) use ($id) { $data[$id]['lastSeen'] = time() - 50; return $data; });
        $offline = tunnelManager::listing('admin-fixture')['tunnels'][0];
        check($offline['status'] === 'offline' && $offline['url'] === '', 'Stale connection kept live link');
        $newSession = tunnelManager::agent($secret)['session'];
        check($newSession !== $session && agentRequest($secret, 'POST', $report)->code === 403, 'Replaced process can still report');
        tunnelManager::mutate('admin-fixture', ['action' => 'stop', 'id' => $id], 'https://panel.example');
        check(agentRequest($secret, 'POST', array_replace($report, ['session' => $newSession]))->code === 403, 'Stopped tunnel remained authorized');
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
        $request = new Request(); $response = new Response();
        $request->header['authorization'] = 'Bearer admin-fixture';
        $request->server['request_method'] = 'POST';
        $request->post = fields() + ['settingGroup' => 'tunnels'];
        fileManagerSettings::api($request, $response);
        check($response->code === 200 && isset(json_decode($response->body, true)['command']), 'Settings API failed');
        echo "PASS tunnels: input validation, scoped credentials, generated agent, connection, lease, rotation, stop, revocation, deletion and settings API\n";
    } finally {
        foreach (glob($directory . '/tunnels/*') ?: [] as $file) unlink($file);
        if (is_dir($directory . '/tunnels')) rmdir($directory . '/tunnels');
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
        foreach (['FILEMANAGER_DATA_DIR', 'FILEMANAGER_TOKENS_FILE', 'FILEMANAGER_CONFIG_FILE', 'FILEMANAGER_ORIGIN'] as $name) putenv($name);
    }
}
