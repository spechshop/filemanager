<?php
declare(strict_types=1);
namespace plugins\Request;

/** Serves only pinned, checksum-verified releases from a private cache. */
final class tunnelBinaryCache
{
    private const MAX_BYTES = 104857600;
    private const HOSTS = ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'];

    public static function nodeCatalog(): array
    {
        $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/scripts/tunnel-node.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $catalog['version'] ?? '') || !is_array($catalog['assets'] ?? null)) throw new \RuntimeException('Invalid Node manifest');
        foreach ($catalog['assets'] as $platform => $asset) {
            if (!preg_match('/^(Linux|Darwin):(x86_64|amd64|arm64|aarch64|armv7l|ppc64le|s390x)(:(musl|compat))?$/D', $platform)
                || !is_string($asset['name'] ?? null) || !preg_match('/^node-v' . preg_quote($catalog['version'], '/') . '-(linux-(x64|arm64|armv7l|ppc64le|s390x)(-musl|-glibc-217)?|darwin-(x64|arm64))\.tar\.gz$/D', $asset['name'])
                || !is_string($asset['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $asset['sha256'])
                || !in_array($asset['host'] ?? '', ['nodejs.org', 'unofficial-builds.nodejs.org'], true)) throw new \RuntimeException('Invalid Node asset');
        }
        return $catalog;
    }

    public static function file(string $name): string
    {
        $node = str_starts_with($name, 'node-');
        $catalog = $node ? self::nodeCatalog() : json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/scripts/tunnel-cloudflared.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!preg_match('/^[0-9.]+$/D', $catalog['version'] ?? '')) throw new \RuntimeException('Invalid release manifest');
        $digest = null;
        $host = '';
        foreach ($catalog['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? null) === $name) { $digest = $asset['sha256'] ?? null; $host = $asset['host'] ?? ''; break; }
        }
        if ((!$node && !preg_match('/^cloudflared-(linux|darwin)-(amd64|arm64|arm|386)(\.tgz)?$/D', $name))
            || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new \InvalidArgumentException('Binário não permitido.');
        $directory = securityPolicy::dataPath('tunnel-binaries', dirname(__DIR__, 3) . '/.runtime/tunnel-binaries');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Binary cache unavailable');
        if (is_link($directory)) throw new \RuntimeException('Unsafe binary cache');
        $path = $directory . '/' . $catalog['version'] . '-' . $name;
        if (is_link($path) || is_link($path . '.lock')) throw new \RuntimeException('Unsafe binary cache entry');
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Binary cache lock unavailable');
        $temporary = null;
        try {
            // Another request may be downloading. Return 503 so curl can retry without blocking heartbeats.
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Binary download already in progress');
            if (is_file($path) && filesize($path) <= self::MAX_BYTES && hash_equals($digest, (string) hash_file('sha256', $path))) return $path;
            $temporary = tempnam($directory, $node ? '.node-' : '.cloudflared-');
            if ($temporary === false) throw new \RuntimeException('Cannot reserve binary download');
            chmod($temporary, 0600);
            $url = $node
                ? 'https://' . $host . ($host === 'nodejs.org' ? '/dist/v' : '/download/release/v') . $catalog['version'] . '/' . $name
                : 'https://github.com/cloudflare/cloudflared/releases/download/' . $catalog['version'] . '/' . $name;
            self::download($url, $temporary, $node ? ['nodejs.org', 'unofficial-builds.nodejs.org'] : self::HOSTS);
            if (!hash_equals($digest, (string) hash_file('sha256', $temporary))) throw new \RuntimeException('Official binary SHA256 mismatch');
            if (!rename($temporary, $path)) throw new \RuntimeException('Cannot publish binary cache entry');
            $temporary = null;
            return $path;
        } finally {
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function download(string $url, string $destination, array $hosts): void
    {
        $deadline = microtime(true) + 120;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $parts = parse_url($url);
            if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
                throw new \RuntimeException('Unexpected binary download host');
            }
            $context = stream_context_create([
                'http' => ['timeout' => 15, 'follow_location' => 0, 'ignore_errors' => true,
                    'header' => "User-Agent: FileManager-Tunnel/1.0\r\nAccept-Encoding: identity\r\nConnection: close\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $parts['host']],
            ]);
            $input = @fopen($url, 'rb', false, $context);
            if ($input === false) throw new \RuntimeException('Official download unavailable');
            try {
                $status = 0; $location = null;
                foreach (stream_get_meta_data($input)['wrapper_data'] ?? [] as $header) {
                    if (preg_match('~^HTTP/\S+\s+([0-9]{3})~', $header, $match)) $status = (int) $match[1];
                    if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                }
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && strlen($location) <= 8192) {
                    $url = $location;
                    continue;
                }
                if ($status !== 200) throw new \RuntimeException('Official download returned HTTP ' . $status);
                $output = fopen($destination, 'wb');
                if ($output === false) throw new \RuntimeException('Cannot write binary download');
                try {
                    $bytes = 0;
                    while (!feof($input)) {
                        if (microtime(true) > $deadline) throw new \RuntimeException('Binary download timed out');
                        $chunk = fread($input, 262144);
                        if ($chunk === false || ($chunk === '' && !feof($input))) throw new \RuntimeException('Binary download interrupted');
                        $bytes += strlen($chunk);
                        if ($bytes > self::MAX_BYTES || fwrite($output, $chunk) !== strlen($chunk)) throw new \RuntimeException('Invalid binary download size');
                    }
                } finally { fclose($output); }
                return;
            } finally { fclose($input); }
        }
        throw new \RuntimeException('Too many binary download redirects');
    }
}
