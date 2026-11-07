<?php
declare(strict_types=1);
namespace plugins\Request;

/** Durable tunnel definitions; agents receive a separate, revocable capability. */
final class tunnelManager
{
    public const LEASE_SECONDS = 40;

    private static function path(): string
    {
        $directory = securityPolicy::dataPath('tunnels', dirname(__DIR__, 3) . '/.runtime/tunnels');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Tunnel storage unavailable');
        if (is_link($directory)) throw new \RuntimeException('Unsafe tunnel storage');
        return $directory . '/registry.json';
    }

    private static function ownerActive(string $owner): bool
    {
        foreach (securityPolicy::tokens() as $key => $_) {
            if (hash_equals($owner, hash('sha256', (string) $key))) return securityPolicy::adminAllowed((string) $key);
        }
        return false;
    }

    private static function panelCertificatePin(): string
    {
        $root = dirname(__DIR__, 3);
        $file = getenv('FILEMANAGER_CONFIG_FILE') ?: $root . '/plugins/configInterface.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $certificate = $config['serverSettings']['ssl_cert_file'] ?? '';
        if (!is_string($certificate) || $certificate === '' || !function_exists('openssl_pkey_get_public')) return '';
        if (!str_starts_with($certificate, '/')) $certificate = $root . '/' . $certificate;
        if (!is_file($certificate)) return '';
        $key = openssl_pkey_get_public((string) file_get_contents($certificate));
        if ($key === false) return '';
        $details = openssl_pkey_get_details($key);
        if ($details === false) return '';
        $der = base64_decode(preg_replace('/-----[^\n]+-----|\s+/', '', $details['key']), true);
        return $der === false ? '' : 'sha256//' . base64_encode(hash('sha256', $der, true));
    }

    public static function script(array $config): string
    {
        $root = dirname(__DIR__, 3);
        if (($config['mode'] ?? 'cloudflare') === 'direct') {
            $source = file_get_contents($root . '/scripts/tunnel-direct-agent.cjs');
            $launcher = file_get_contents($root . '/scripts/tunnel-direct-agent.sh');
            if ($source === false || $launcher === false) throw new \RuntimeException('Direct tunnel installer unavailable');
            $payload = base64_encode(str_replace('// FILEMANAGER_CONFIG', 'const config = ' . json_encode($config + ['managedByLauncher' => true], JSON_THROW_ON_ERROR) . ';', $source));
            $header = '';
            foreach (['endpoint' => 'FM_ENDPOINT', 'secret' => 'FM_SECRET', 'session' => 'FM_SESSION', 'panelPin' => 'FM_PANEL_PIN'] as $field => $variable) {
                $header .= $variable . '=' . escapeshellarg($config[$field] ?? '') . "\n";
            }
            $catalog = tunnelBinaryCache::nodeCatalog();
            $cases = '';
            foreach ($catalog['assets'] as $platform => $asset) {
                $url = 'https://' . $asset['host'] . ($asset['host'] === 'nodejs.org' ? '/dist/v' : '/download/release/v') . $catalog['version'] . '/' . $asset['name'];
                $cases .= '            ' . $platform . ') FM_ASSET=' . escapeshellarg($asset['name']) . '; FM_SHA256=' . escapeshellarg($asset['sha256']) . '; FM_NODE_URL=' . escapeshellarg($url) . ";;\n";
            }
            return strtr($launcher, ['FILEMANAGER_AGENT_BASE64' => $payload, '# FILEMANAGER_CONFIG' => $header, '# FILEMANAGER_NODE_PLATFORMS' => $cases]);
        }
        $source = file_get_contents($root . '/scripts/tunnel-agent.sh');
        $manifest = json_decode((string) file_get_contents($root . '/scripts/tunnel-cloudflared.json'), true, 16, JSON_THROW_ON_ERROR);
        if ($source === false || !preg_match('/^[0-9.]+$/D', $manifest['version'] ?? '')) throw new \RuntimeException('Tunnel installer unavailable');
        $header = '';
        foreach (['endpoint' => 'FM_ENDPOINT', 'secret' => 'FM_SECRET', 'session' => 'FM_SESSION', 'target' => 'FM_TARGET', 'panelPin' => 'FM_PANEL_PIN'] as $field => $variable) {
            $header .= $variable . '=' . escapeshellarg($config[$field] ?? '') . "\n";
        }
        $header .= 'FM_NO_TLS_VERIFY=' . (!empty($config['noTlsVerify']) ? 'true' : 'false') . "\n";
        $cases = '';
        foreach ($manifest['assets'] as $platform => $asset) {
            if (!preg_match('/^(Linux|Darwin):(x86_64|amd64|arm64|aarch64|armv7l|i386|i686)$/D', $platform)
                || !preg_match('/^cloudflared-(linux|darwin)-(amd64|arm64|arm|386)(\.tgz)?$/D', $asset['name'])
                || !preg_match('/^[a-f0-9]{64}$/D', $asset['sha256'])) throw new \RuntimeException('Invalid tunnel manifest');
            $cases .= '        ' . $platform . ') FM_ASSET=' . escapeshellarg($asset['name']) . '; FM_SHA256=' . escapeshellarg($asset['sha256']) . ";;\n";
        }
        return strtr($source, ['# FILEMANAGER_CONFIG' => $header,
            '# FILEMANAGER_PLATFORMS' => $cases, 'FILEMANAGER_CLOUDFLARED_VERSION' => $manifest['version']]);
    }

    public static function listing(string $actor): array
    {
        securityPolicy::requireAdmin($actor);
        $result = [];
        foreach (privateJson::read(self::path()) as $id => $entry) {
            $status = $entry['status'];
            if (!$entry['enabled'] || !self::ownerActive($entry['owner'])) $status = 'stopped';
            elseif (in_array($status, ['online', 'starting'], true) && $entry['lastSeen'] < time() - self::LEASE_SECONDS) $status = 'offline';
            $result[] = [
                'id' => $id, 'name' => $entry['name'], 'target' => $entry['target'],
                'mode' => $entry['mode'] ?? 'cloudflare', 'remotePort' => $entry['remotePort'] ?? null,
                'noTlsVerify' => $entry['noTlsVerify'], 'status' => $status,
                'url' => $status === 'online' ? $entry['url'] : '',
                'lastSeen' => $entry['lastSeen'], 'message' => $entry['message'],
            ];
        }
        securityPolicy::requireAdmin($actor);
        return ['success' => true, 'tunnels' => $result];
    }

    private static function target(array $input): string
    {
        $host = $input['localHost'] ?? '';
        $port = $input['localPort'] ?? '';
        $protocol = $input['protocol'] ?? 'http';
        if (!is_string($host) || strlen($host) > 253 || !is_string($port) || !preg_match('/^[0-9]{1,5}$/D', $port)
            || strlen($port) > 5 || (int) $port < 1 || (int) $port > 65535 || !in_array($protocol, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Informe host, protocolo e porta local (1 a 65535) válidos.');
        }
        $host = trim($host);
        if (str_contains($host, '://')) {
            $parts = parse_url($host);
            if ($parts === false || array_diff(array_keys($parts), ['scheme', 'host', 'path'])
                || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new \InvalidArgumentException('Use somente o host local, sem credenciais, caminho ou porta.');
            }
            $host = $parts['host'] ?? '';
            $protocol = $parts['scheme'];
        }
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $host = '[' . $host . ']';
        elseif (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('Host local inválido. Exemplo: 127.0.0.1 ou localhost.');
        }
        return $protocol . '://' . $host . ':' . (int) $port;
    }

    public static function mutate(string $actor, array $input, string $origin): array
    {
        securityPolicy::requireAdmin($actor);
        $parts = parse_url($origin);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) || preg_match('/[\s\x00-\x1f]/', $origin)) {
            throw new \InvalidArgumentException('Endereço do painel inválido.');
        }
        $action = $input['action'] ?? '';
        if (!in_array($action, ['create', 'command', 'stop', 'delete'], true)) throw new \InvalidArgumentException('Ação de túnel inválida.');
        $secret = null;
        $panelPin = '';
        $id = $input['id'] ?? '';
        privateJson::update(self::path(), static function (array $entries) use ($actor, $input, $origin, $action, &$secret, &$id, &$panelPin): array {
            securityPolicy::requireAdmin($actor);
            if ($action === 'create') {
                if (count($entries) >= 100) throw new \InvalidArgumentException('Limite de 100 túneis atingido.');
                $target = self::target($input);
                $mode = $input['mode'] ?? 'cloudflare';
                if (!in_array($mode, ['cloudflare', 'direct'], true)) throw new \InvalidArgumentException('Modo de túnel inválido.');
                $remotePort = null;
                if ($mode === 'direct') {
                    $value = $input['remotePort'] ?? '';
                    if (!is_string($value) || !preg_match('/^[0-9]{4,5}$/D', $value) || (int) $value < 1024 || (int) $value > 65535) {
                        throw new \InvalidArgumentException('Informe uma porta de 1024 a 65535 para abrir no host do File Manager.');
                    }
                    $remotePort = (int) $value;
                    foreach ($entries as $entry) {
                        if (($entry['mode'] ?? 'cloudflare') === 'direct' && $entry['remotePort'] === $remotePort) throw new \InvalidArgumentException('Esta porta já está reservada por outro túnel.');
                    }
                    if ($remotePort === (int) (parse_url($origin, PHP_URL_PORT) ?: (str_starts_with($origin, 'https://') ? 443 : 80))) {
                        throw new \InvalidArgumentException('Escolha uma porta diferente da porta do painel.');
                    }
                }
                $name = $input['name'] ?? '';
                if (!is_string($name) || strlen($name) > 120) throw new \InvalidArgumentException('Use um nome de até 120 caracteres.');
                if (!in_array($input['noTlsVerify'] ?? 'false', ['true', 'false'], true)) throw new \InvalidArgumentException('Opção TLS inválida.');
                $id = bin2hex(random_bytes(12));
                $entries[$id] = ['name' => trim($name) ?: $target, 'target' => $target, 'mode' => $mode, 'remotePort' => $remotePort,
                    'noTlsVerify' => $mode === 'cloudflare' && str_starts_with($target, 'https://') && ($input['noTlsVerify'] ?? 'false') === 'true'];
            } elseif (!is_string($id) || !isset($entries[$id])) {
                throw new \InvalidArgumentException('Túnel não encontrado. Atualize a lista.');
            }
            if ($action === 'delete') { unset($entries[$id]); return $entries; }
            if ($action === 'stop') {
                $entries[$id]['enabled'] = false;
                $entries[$id]['status'] = 'stopped';
                $entries[$id]['url'] = '';
                return $entries;
            }
            $secret = bin2hex(random_bytes(32));
            $panelPin = self::panelCertificatePin();
            securityPolicy::requireAdmin($actor);
            $entries[$id] = array_replace($entries[$id], [
                'owner' => hash('sha256', $actor), 'secretHash' => hash('sha256', $secret), 'origin' => $origin,
                'panelPin' => $panelPin,
                'enabled' => true, 'status' => 'waiting', 'url' => '', 'lastSeen' => 0, 'session' => '', 'message' => '',
            ]);
            return $entries;
        });
        $result = ['success' => true, 'message' => $action === 'stop' ? 'Desconexão solicitada; a máquina remota encerrará o túnel em até 15 segundos.' : 'Operação concluída.', 'id' => $id];
        if ($secret !== null) {
            $url = escapeshellarg($origin . '/tunnel/' . $secret);
            $download = 'curl -fsS --connect-timeout 10 --max-time 30 ' . $url;
            if (str_starts_with($origin, 'https://') && $panelPin !== '') {
                // Buffer the script so a recovered CA/hostname failure is never printed as an error.
                // A failed download must never pass diagnostic text or a partial script to Bash.
                $download = '(fm_curl_error=$(mktemp) || exit 1; trap \'rm -f -- "$fm_curl_error"\' EXIT; '
                    . 'fm_tunnel_script=$(' . $download . ' 2>"$fm_curl_error"); fm_curl_status=$?; if [ "$fm_curl_status" -eq 60 ]; then '
                    . 'curl -fsS --connect-timeout 10 --max-time 30 --insecure --pinnedpubkey ' . escapeshellarg($panelPin) . ' ' . $url
                    . '; elif [ "$fm_curl_status" -eq 0 ]; then printf \'%s\\n\' "$fm_tunnel_script"; '
                    . 'else cat "$fm_curl_error" >&2; exit "$fm_curl_status"; fi)';
            }
            $result['command'] = $download . ' | bash';
        }
        securityPolicy::requireAdmin($actor);
        return $result;
    }

    /** Binary requests cannot claim sessions or extend their heartbeat lease. */
    public static function authorizeDownload(string $secret, string $session): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret) || !preg_match('/^[a-f0-9]{48}$/D', $session)) throw new \DomainException('Invalid agent credential');
        $hash = hash('sha256', $secret);
        foreach (privateJson::read(self::path()) as $entry) {
            if (hash_equals($entry['secretHash'], $hash) && $entry['enabled'] && hash_equals($entry['session'], $session)
                && $entry['lastSeen'] >= time() - self::LEASE_SECONDS && self::ownerActive($entry['owner'])) return;
        }
        throw new \DomainException('Agent download denied');
    }

    public static function directUrl(array $entry): string
    {
        return parse_url($entry['target'], PHP_URL_SCHEME) . '://' . parse_url($entry['origin'], PHP_URL_HOST) . ':' . $entry['remotePort'];
    }

    /** A direct relay may only bind the port reserved by its active capability. */
    public static function authorizeDirect(string $secret, string $session): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret) || !preg_match('/^[a-f0-9]{48}$/D', $session)) throw new \DomainException('Invalid agent credential');
        foreach (privateJson::read(self::path()) as $entry) {
            if (($entry['mode'] ?? 'cloudflare') === 'direct' && hash_equals($entry['secretHash'], hash('sha256', $secret))
                && $entry['enabled'] && hash_equals($entry['session'], $session)
                && $entry['lastSeen'] >= time() - self::LEASE_SECONDS && self::ownerActive($entry['owner'])) return $entry;
        }
        throw new \DomainException('Direct tunnel denied');
    }

    /** GET claims a session; subsequent heartbeats cannot be replayed by an older process. */
    public static function agent(string $secret, ?array $report = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) throw new \DomainException('Invalid agent credential');
        $result = [];
        privateJson::update(self::path(), static function (array $entries) use ($secret, $report, &$result): array {
            $hash = hash('sha256', $secret);
            foreach ($entries as &$entry) {
                if (!hash_equals($entry['secretHash'], $hash)) continue;
                if (!$entry['enabled'] || !self::ownerActive($entry['owner'])) throw new \DomainException('Tunnel disabled');
                if ($report === null) {
                    if (in_array($entry['status'], ['starting', 'online'], true) && $entry['lastSeen'] >= time() - self::LEASE_SECONDS) {
                        throw new \UnexpectedValueException('Este comando já está conectado. Gere outro comando para substituir a conexão.');
                    }
                    $entry['session'] = bin2hex(random_bytes(24));
                    $entry['status'] = 'starting';
                    $entry['url'] = '';
                    $entry['message'] = '';
                    $result = ['endpoint' => $entry['origin'] . '/fileManagerTunnelAgent', 'secret' => $secret,
                        'mode' => $entry['mode'] ?? 'cloudflare',
                        'panelPin' => str_starts_with($entry['panelPin'] ?? '', 'sha256//') ? $entry['panelPin'] : '',
                        'session' => $entry['session'], 'target' => $entry['target'], 'noTlsVerify' => $entry['noTlsVerify']];
                } else {
                    if (!is_string($report['session'] ?? null) || !hash_equals($entry['session'], $report['session'])) throw new \DomainException('Session replaced');
                    $status = $report['status'] ?? '';
                    $url = $report['url'] ?? '';
                    $validUrl = ($entry['mode'] ?? 'cloudflare') === 'direct'
                        ? $url === self::directUrl($entry)
                        : is_string($url) && preg_match('~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com$~D', $url);
                    if (!in_array($status, ['starting', 'online', 'offline', 'error'], true) || !is_string($url)
                        || ($url !== '' && !$validUrl)
                        || ($status === 'online' && $url === '')) throw new \InvalidArgumentException('Estado do túnel inválido.');
                    $message = $report['message'] ?? '';
                    if (!is_string($message) || strlen($message) > 300) throw new \InvalidArgumentException('Mensagem inválida.');
                    $entry['status'] = $status;
                    $entry['url'] = $status === 'online' ? $url : '';
                    $entry['message'] = $message;
                    $result = ['success' => true];
                }
                $entry['lastSeen'] = time();
                unset($entry);
                return $entries;
            }
            unset($entry);
            throw new \DomainException('Invalid agent credential');
        });
        return $result;
    }
}
