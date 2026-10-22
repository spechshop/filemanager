<?php
declare(strict_types=1);
namespace plugins\Request;

/** Runtime-independent policy. Authentication always reads the current token store. */
final class securityPolicy
{
    public static function tokens(): array
    {
        $file = getenv('FILEMANAGER_TOKENS_FILE') ?: rtrim(appController::baseDir(), '/') . '/database/tokens.lotus';
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public static function validToken($token): bool
    {
        if (!is_string($token) || $token === '' || strlen($token) > 256) return false;
        $record = self::tokens()[$token] ?? null;
        return is_array($record) && empty($record['revoked']) && (int) ($record['expire'] ?? 0) > time();
    }

    public static function requestToken($request): string
    {
        $values = [];
        $header = $request->header['authorization'] ?? '';
        if (is_string($header) && preg_match('/^Bearer (\S+)$/D', $header, $match)) $values[] = $match[1];
        foreach ([$request->get ?? [], $request->post ?? []] as $parameters) {
            if (isset($parameters['tokenBrowser'])) $values[] = $parameters['tokenBrowser'];
        }
        if (!$values) return '';
        foreach ($values as $value) if (!is_string($value) || $value === '' || $value !== $values[0]) return '';
        return $values[0];
    }

    public static function originAllowed($request, bool $websocket = false): bool
    {
        if (($request->header['sec-fetch-site'] ?? '') === 'cross-site') return false;
        $origin = $request->header['origin'] ?? '';
        $configured = getenv('FILEMANAGER_ORIGIN') ?: '';
        if ($origin === '') return !$websocket;
        if (!is_string($origin) || $origin === 'null') return false;
        if ($configured !== '') return hash_equals(rtrim($configured, '/'), $origin);
        $host = $request->header['host'] ?? '';
        return $host !== '' && in_array($origin, ['https://' . $host, 'http://' . $host], true);
    }

    public static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    public static function roots(string $token = ''): array
    {
        $env = getenv('FILEMANAGER_ALLOWED_ROOTS');
        $roots = $env ? json_decode($env, true) : [appController::baseDir() . 'files'];
        if (!is_array($roots) || !$roots) throw new \DomainException('roots');
        $allowed = [];
        $app = rtrim((string) realpath(appController::baseDir()), '/');
        foreach ($roots as $root) {
            $real = is_string($root) ? realpath($root) : false;
            if ($real === false || !is_dir($real) || $real === '/' || self::within($app, $real)
                || (self::within($real, $app) && !self::within($real, $app . '/files'))) throw new \DomainException('roots');
            $allowed[] = $real;
        }
        $record = $token !== '' ? (self::tokens()[$token] ?? null) : [];
        if ($token !== '' && (!is_array($record) || !empty($record['revoked']) || (int) ($record['expire'] ?? 0) <= time())) throw new \DomainException('revoked');
        $scope = $record['allowedRoots'] ?? null;
        if ($scope !== null) {
            if (!is_array($scope)) throw new \DomainException('scope');
            $scoped = [];
            foreach ($scope as $root) {
                $real = is_string($root) ? realpath($root) : false;
                if ($real !== false && is_dir($real)) foreach ($allowed as $parent) {
                    if (self::within($real, $parent)) { $scoped[] = $real; break; }
                }
            }
            $allowed = $scoped;
        }
        if (!$allowed) throw new \DomainException('scope');
        return array_values(array_unique($allowed));
    }

    public static function path($input, string $token = '', bool $allowRoot = true): string
    {
        if (!is_string($input) || $input === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $input)
            || str_contains($input, '://') || in_array('..', explode('/', $input), true)) throw new \DomainException('path');
        $roots = self::roots($token);
        $path = str_starts_with($input, '/') ? $input : appController::baseDir() . $input;
        $current = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '.' || $part === '') continue;
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) throw new \DomainException('symlink');
        }
        $path = $current ?: '/'; $probe = $path; $suffix = '';
        while (!file_exists($probe)) {
            if ($probe === '/' || dirname($probe) === $probe) throw new \DomainException('path');
            $suffix = '/' . basename($probe) . $suffix;
            $probe = dirname($probe);
        }
        $real = realpath($probe);
        if ($real === false) throw new \DomainException('path');
        $resolved = $real . $suffix;
        foreach ($roots as $root) if (self::within($resolved, $root) && ($allowRoot || $resolved !== $root)) return $resolved;
        throw new \DomainException('path');
    }

    public static function filename($name): string
    {
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..'
            || preg_match('~[/\\\\\x00-\x1f\x7f]~', $name)) throw new \DomainException('filename');
        return $name;
    }

    public static function tree(string $path, string $token): void
    {
        self::path($path, $token);
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) self::path($item->getPathname(), $token);
    }

    public static function preflight($request, string $token): void
    {
        $route = basename($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        if (in_array($route, ['fileManagerDiagnostics', 'fileManagerServices', 'fileManagerSettings', 'fileManagerSystem', 'freeRam', 'chat4o', 'codex', 'phpstanCheck', 'codeGenerate', 'toggleStubsPath', 'stub'], true)
            && !self::terminalAllowed($token)) throw new \DomainException('capability');
        $json = json_decode($request->rawContent() ?: '{}', true);
        // Validate every source independently; a JSON field must not hide a GET path.
        $sources = [$request->get ?? [], $request->post ?? [], is_array($json) ? $json : []];
        foreach ($sources as $data) {
            foreach (['path', 'dest', 'dir', 'nameFile', 'fullPath', 'newName'] as $key) {
                if (!isset($data[$key]) || $data[$key] === '') continue;
                if ($key === 'dir' && $data[$key] === '/' && $route === 'treeDetails') continue;
                self::path($data[$key], $token, !in_array($route, ['deleteFile', 'renameItem'], true));
            }
            if ($route === 'newFile' && isset($data['name'])) {
                self::filename($data['name']);
                self::path(($data['path'] ?? '') . '/' . $data['name'], $token, false);
            }
            if ($route === 'decompressFile' && isset($data['namefile'])) self::filename($data['namefile']);
            if (isset($data['files'])) {
                if (!is_array($data['files'])) throw new \DomainException('files');
                foreach ($data['files'] as $file) self::tree(self::path($file, $token, false), $token);
            }
            if (in_array($route, ['saveTerminal', 'deleteTerminal'], true) && isset($data['id'])) {
                if (!is_string($data['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $data['id'])) throw new \DomainException('session');
            }
        }
        $data = array_merge(...$sources);
        if ($route === 'deleteMultipleFiles') foreach ($request->post ?? [] as $key => $files) {
            if ($key === 'tokenBrowser') continue;
            if (!is_array($files)) throw new \DomainException('files');
            foreach ($files as $file) self::path($file, $token, false);
        }
        if (in_array($route, ['deleteFile', 'cutCopy', 'compressMultipleFiles'], true) && isset($data['path'])) self::tree(self::path($data['path'], $token), $token);
        if ($route === 'uploadFile') {
            $target = self::path($data['path'] ?? '', $token);
            foreach ($request->files['files'] ?? [] as $file) self::path($target . '/' . self::filename($file['name'] ?? null), $token, false);
        }
    }

    public static function ownsSocket(string $owner, $socket): bool
    {
        return is_string($socket) && $owner !== '' && ($socket === $owner
            || (str_starts_with($socket, $owner . '-') && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', substr($socket, strlen($owner) + 1)) === 1));
    }

    public static function terminalAllowed(string $token): bool
    {
        $record = self::tokens()[$token] ?? [];
        return self::validToken($token) && ($record['terminal'] ?? !isset($record['allowedRoots'])) === true;
    }

    public static function socketOwner(string $socket): string
    {
        $matches = [];
        foreach (self::tokens() as $token => $_) if (is_string($token) && self::ownsSocket($token, $socket)) $matches[] = $token;
        return count($matches) === 1 ? $matches[0] : '';
    }

    public static function bridgeSecret(): string
    {
        $secret = getenv('FILEMANAGER_BRIDGE_SECRET') ?: @file_get_contents(appController::baseDir() . '.runtime/bridge-secret');
        return is_string($secret) ? trim($secret) : '';
    }

    public static function bridgeAllowed($request, string $socket): bool
    {
        $secret = self::bridgeSecret(); $provided = $request->header['x-filemanager-bridge'] ?? '';
        return strlen($secret) >= 32 && is_string($provided) && hash_equals($secret, $provided)
            && empty($request->header['origin']) && self::terminalAllowed(self::socketOwner($socket));
    }

    public static function log(string $event, string $channel = 'http'): void
    {
        // Controlled labels only: no URLs, payloads, filenames, tokens or commands.
        error_log(json_encode(['event' => $event, 'channel' => $channel, 'time' => time()]));
    }
}
