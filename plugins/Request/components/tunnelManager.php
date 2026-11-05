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
                $name = $input['name'] ?? '';
                if (!is_string($name) || strlen($name) > 120) throw new \InvalidArgumentException('Use um nome de até 120 caracteres.');
                if (!in_array($input['noTlsVerify'] ?? 'false', ['true', 'false'], true)) throw new \InvalidArgumentException('Opção TLS inválida.');
                $id = bin2hex(random_bytes(12));
                $entries[$id] = ['name' => trim($name) ?: $target, 'target' => $target,
                    'noTlsVerify' => str_starts_with($target, 'https://') && ($input['noTlsVerify'] ?? 'false') === 'true'];
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
                // Retry a CA/hostname validation failure only with the configured server key pinned.
                $download = '(' . $download . '; fm_curl_status=$?; if [ "$fm_curl_status" -eq 60 ]; then '
                    . 'curl -fsS --connect-timeout 10 --max-time 30 --insecure --pinnedpubkey ' . escapeshellarg($panelPin) . ' ' . $url
                    . '; else exit "$fm_curl_status"; fi)';
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
                        'panelPin' => str_starts_with($entry['panelPin'] ?? '', 'sha256//') ? $entry['panelPin'] : '',
                        'session' => $entry['session'], 'target' => $entry['target'], 'noTlsVerify' => $entry['noTlsVerify']];
                } else {
                    if (!is_string($report['session'] ?? null) || !hash_equals($entry['session'], $report['session'])) throw new \DomainException('Session replaced');
                    $status = $report['status'] ?? '';
                    $url = $report['url'] ?? '';
                    if (!in_array($status, ['starting', 'online', 'offline', 'error'], true) || !is_string($url)
                        || ($url !== '' && !preg_match('~^https://[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com$~D', $url))
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
