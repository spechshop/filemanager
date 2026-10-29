<?php
declare(strict_types=1);
namespace plugins\Request;

/** Runtime-independent policy. Authentication always reads the current token store. */
final class securityPolicy
{
    /** Explicit HTTP surface: internal plugin classes are never route targets. */
    public const OPERATIONS = [
        'chat4o' => 'admin',
        'checkToken' => 'files',
        'accessTicket' => 'files',
        'codeGenerate' => 'admin',
        'codeInfo' => 'files',
        'codex' => 'admin',
        'compressMultipleFiles' => 'files',
        'cutCopy' => 'files',
        'decompressFile' => 'files',
        'deleteFile' => 'files',
        'deleteMultipleFiles' => 'files',
        'deleteTerminal' => 'terminal',
        'downloadFile' => 'files',
        'fileManagerDiagnostics' => 'admin',
        'fileManagerServices' => 'admin',
        'fileManagerSettings' => 'admin',
        'fileManagerSystem' => 'admin',
        'formatCode' => 'files',
        'freeRam' => 'admin',
        'getArchiveDetails' => 'files',
        'getFile' => 'files',
        'getPreview' => 'files',
        'getTerminals' => 'terminal',
        'imageBase64' => 'files',
        'imageBase643' => 'files',
        'importGit' => 'files',
        'newFile' => 'files',
        'phpParser' => 'files',
        'phpParserBackup' => 'files',
        'phpstanCheck' => 'admin',
        'refactorFile' => 'files',
        'renameItem' => 'files',
        'saveTerminal' => 'terminal',
        'schemaPath' => 'files',
        'searchInFile' => 'files',
        'stub' => 'admin',
        'syncPath' => 'files',
        'toggleStubsPath' => 'admin',
        'treeDetails' => 'files',
        'trash' => 'files',
        'uploadFile' => 'files',
    ];

    public static function operation(string $route): string
    {
        // Accept whole segments only; never concatenate slash-separated names.
        if (!preg_match('~^/*([a-zA-Z][a-zA-Z0-9]*)/*$~D', $route, $match)) throw new \DomainException('route');
        foreach (self::OPERATIONS as $name => $_) if (strcasecmp($name, $match[1]) === 0) return $name;
        throw new \DomainException('route');
    }

    public static function handlerOperation(string $handler): string
    {
        $prefix = __NAMESPACE__ . '\\';
        if (!str_starts_with($handler, $prefix)) throw new \DomainException('handler');
        return self::operation(substr($handler, strlen($prefix)));
    }

    public static function adminAllowed(string $token): bool
    {
        $record = self::record($token);
        return $record !== null && ($record['admin'] ?? (!array_key_exists('allowedRoots', $record) && ($record['terminal'] ?? true) === true)) === true;
    }

    public static function tokens(): array
    {
        $file = self::tokenFile();
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public static function requireAdmin(string $token): void
    {
        if (!self::adminAllowed($token)) throw new \DomainException('Administrative permission revoked');
    }

    public static function expiration($value): int
    {
        if (!is_int($value) && !(is_string($value) && strlen($value) <= 16 && ctype_digit($value))) return 0;
        $expire = (int) $value;
        return $expire > 9999999999 ? intdiv($expire, 1000) : $expire;
    }

    public static function record($token): ?array
    {
        if (!is_string($token) || $token === '' || strlen($token) > 256) return null;
        $record = self::tokens()[$token] ?? null;
        return is_array($record) && empty($record['revoked']) && self::expiration($record['expire'] ?? null) > time() ? $record : null;
    }

    public static function validToken($token): bool { return self::record($token) !== null; }

    public static function requestToken($request): string
    {
        $values = [];
        $header = $request->header['authorization'] ?? '';
        if ($header !== '') {
            if (!is_string($header) || !preg_match('/^Bearer[ \t]+(\S+)$/iD', $header, $match)) return '';
            $values[] = $match[1];
        }
        foreach ([$request->get ?? [], $request->post ?? []] as $parameters) {
            if (isset($parameters['tokenBrowser'])) $values[] = $parameters['tokenBrowser'];
        }
        if (isset($request->get['accessTicket'])) {
            $ticket = self::readTicket($request->get['accessTicket']);
            if (($ticket['kind'] ?? '') !== 'media' || ($ticket['path'] ?? null) !== ($request->get['path'] ?? null)) return '';
            $values[] = $ticket['owner'] ?? '';
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

    public static function limit(string $name, int $default): int
    {
        $value = getenv('FILEMANAGER_' . $name);
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
    }

    public static function dataPath(string $name, string $legacy): string
    {
        $directory = getenv('FILEMANAGER_DATA_DIR');
        return $directory ? rtrim($directory, '/') . '/' . $name : $legacy;
    }

    public static function temporaryDirectory(): string
    {
        $directory = getenv('FILEMANAGER_TEMP_DIR') ?: self::dataPath('tmp', appController::baseDir() . '.runtime/tmp');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Private temporary directory unavailable');
        if (is_link($directory) || !is_writable($directory) || (fileperms($directory) & 0077) !== 0) throw new \RuntimeException('Temporary directory must be private');
        return $directory;
    }

    public static function temporary(string $prefix, string $suffix = ''): string
    {
        $file = tempnam(self::temporaryDirectory(), $prefix);
        if ($file === false) throw new \RuntimeException('Temporary allocation failed');
        if (dirname($file) !== realpath(self::temporaryDirectory())) { unlink($file); throw new \RuntimeException('Unsafe temporary fallback'); }
        if ($suffix !== '' && !rename($file, $file . $suffix)) { unlink($file); throw new \RuntimeException('Temporary reservation failed'); }
        return $file . $suffix;
    }

    public static function uploadDirectory(): string
    {
        $directory = getenv('FILEMANAGER_UPLOAD_TMP_DIR') ?: self::dataPath('upload-tmp', appController::baseDir() . '.runtime/upload-tmp');
        return realpath($directory) ?: $directory;
    }

    public static function tokenFile(): string
    {
        return getenv('FILEMANAGER_TOKENS_FILE') ?: rtrim(appController::baseDir(), '/') . '/database/tokens.lotus';
    }

    public static function roots(string $token = ''): array
    {
        $record = self::record($token);
        if (!is_array($record)) throw new \DomainException('revoked');
        if (!array_key_exists('allowedRoots', $record)) {
            if (self::adminAllowed($token)) return ['/'];
            return self::legacyRoots();
        }
        if (!is_array($record['allowedRoots']) || !$record['allowedRoots']) throw new \DomainException('scope');
        $roots = [];
        foreach ($record['allowedRoots'] as $root) {
            $real = is_string($root) ? realpath($root) : false;
            if ($real === false || !is_dir($real)) throw new \DomainException('scope');
            $roots[] = $real;
        }
        return array_values(array_unique($roots));
    }

    public static function legacyRoots(): array
    {
        $configured = getenv('FILEMANAGER_ALLOWED_ROOTS');
        $roots = $configured ? json_decode($configured, true) : [appController::baseDir() . 'files'];
        if (!is_array($roots) || !$roots) throw new \DomainException('scope');
        $app = rtrim((string) realpath(appController::baseDir()), '/');
        $result = [];
        foreach ($roots as $root) {
            $real = is_string($root) ? realpath($root) : false;
            if ($real === false || !is_dir($real) || $real === '/' || self::within($app, $real)
                || (self::within($real, $app) && !self::within($real, $app . '/files'))) throw new \DomainException('scope');
            $result[] = $real;
        }
        return array_values(array_unique($result));
    }

    public static function initialPath(string $token): string
    {
        $roots = self::roots($token);
        if ($roots !== ['/']) return $roots[0];
        $directory = getenv('FILEMANAGER_INITIAL_DIRECTORY');
        if (is_string($directory) && is_dir($directory)) return $directory;
        $configured = json_decode(getenv('FILEMANAGER_ALLOWED_ROOTS') ?: '[]', true);
        $initial = is_array($configured) ? ($configured[0] ?? '') : '';
        return is_string($initial) && is_dir($initial) ? $initial : appController::baseDir() . 'files';
    }

    /** Preliminary validation only. Actual IO MUST use safeFiles descriptor operations. */
    public static function path($input, string $token = '', bool $allowRoot = true): string
    {
        if (!is_string($input) || $input === '' || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $input)
            || str_contains($input, '://')) throw new \DomainException('path');
        $parts = [];
        foreach (explode('/', str_starts_with($input, '/') ? $input : appController::baseDir() . $input) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); continue; }
            $parts[] = $part;
        }
        $path = '/' . implode('/', $parts);
        $probe = $path; $suffix = '';
        while (!file_exists($probe)) {
            if (is_link($probe) || $probe === '/') throw new \DomainException('path');
            $suffix = '/' . basename($probe) . $suffix;
            $probe = dirname($probe);
        }
        $real = realpath($probe);
        if ($real === false) throw new \DomainException('path');
        $resolved = rtrim($real, '/') . $suffix ?: '/';
        foreach (self::roots($token) as $root) if (self::within($resolved, $root) && ($allowRoot || $resolved !== $root)) return $resolved;
        throw new \DomainException('path');
    }

    /** Name operations authorize the directory entry, without dereferencing its final link. */
    public static function entryPath($input, string $token): string
    {
        if (!is_string($input) || $input === '' || str_contains($input, '://')) throw new \DomainException('path');
        $name = self::filename(basename(rtrim($input, '/')));
        $parent = self::path(dirname(rtrim($input, '/')), $token);
        $entry = rtrim($parent, '/') . '/' . $name;
        foreach (self::roots($token) as $root) if (self::within($entry, $root) && $entry !== $root) return $entry;
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
        foreach (safeFiles::walk($path, $token) as $_) { /* Complete descriptor-based preflight. */ }
    }

    public static function preflight($request, string $token, ?string $operation = null): void
    {
        $route = $operation ?? self::operation($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        if (isset($request->get['accessTicket']) && (self::readTicket($request->get['accessTicket'])['operation'] ?? '') !== $route) throw new \DomainException('ticket');
        $capability = self::OPERATIONS[$route] ?? null;
        if ($capability === null || ($capability === 'admin' && !self::adminAllowed($token))
            || ($capability === 'terminal' && !self::terminalAllowed($token))) throw new \DomainException('capability');
        $json = json_decode($request->rawContent() ?: '{}', true);
        // Validate every source independently; a JSON field must not hide a GET path.
        $sources = [$request->get ?? [], $request->post ?? [], is_array($json) ? $json : []];
        foreach ($sources as $data) {
            foreach (['path', 'dest', 'dir', 'nameFile', 'fullPath', 'newName'] as $key) {
                if (!isset($data[$key]) || $data[$key] === '') continue;
                if ($key === 'dir' && $data[$key] === '/' && $route === 'treeDetails') continue;
                if (($route === 'deleteFile' && $key === 'path') || ($route === 'renameItem' && in_array($key, ['path', 'newName'], true))) self::entryPath($data[$key], $token);
                else self::path($data[$key], $token);
            }
            if ($route === 'newFile' && isset($data['name'])) {
                self::filename($data['name']);
                self::path(($data['path'] ?? '') . '/' . $data['name'], $token, false);
            }
            if ($route === 'decompressFile' && isset($data['namefile'])) self::filename($data['namefile']);
            if (isset($data['files'])) {
                if (!is_array($data['files'])) throw new \DomainException('files');
                foreach ($data['files'] as $file) {
                    if (in_array($route, ['deleteMultipleFiles', 'trash'], true)) { self::entryPath($file, $token); if (!is_link($file)) self::tree($file, $token); }
                    else self::tree(self::path($file, $token, false), $token);
                }
            }
            if (in_array($route, ['saveTerminal', 'deleteTerminal'], true) && isset($data['id'])) {
                if (!is_string($data['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $data['id'])) throw new \DomainException('session');
            }
        }
        $data = array_merge(...$sources);
        if ($route === 'deleteMultipleFiles') foreach ($request->post ?? [] as $key => $files) {
            if ($key === 'tokenBrowser') continue;
            if (!is_array($files)) throw new \DomainException('files');
            foreach ($files as $file) self::entryPath($file, $token);
        }
        if (in_array($route, ['deleteFile', 'cutCopy', 'compressMultipleFiles'], true) && isset($data['path']) && !($route === 'deleteFile' && is_link($data['path']))) self::tree(self::path($data['path'], $token), $token);
        if ($route === 'uploadFile') {
            $target = self::path($data['path'] ?? '', $token);
            foreach ($request->files['files'] ?? [] as $file) self::path($target . '/' . self::filename($file['name'] ?? null), $token, false);
        }
    }

    private static function ticketDirectory(): string
    {
        return self::dataPath('access-tickets', appController::baseDir() . '.runtime/access-tickets');
    }

    public static function issueTicket(string $owner, array $scope): string
    {
        if (!self::validToken($owner)) throw new \DomainException('revoked');
        $directory = self::ticketDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('tickets');
        if (is_link($directory)) throw new \RuntimeException('tickets');
        $entries = glob($directory . '/*.json') ?: [];
        foreach ($entries as $entry) if (filemtime($entry) < time() - self::limit('MEDIA_TICKET_SECONDS', 3600)) @unlink($entry);
        if (count($entries) >= self::limit('ACCESS_TICKETS_TOTAL', 10000)) throw new \DomainException('ticket quota');
        $ticket = bin2hex(random_bytes(32));
        $handle = fopen($directory . '/' . $ticket . '.json', 'x');
        if ($handle === false) throw new \RuntimeException('tickets');
        try {
            chmod($directory . '/' . $ticket . '.json', 0600);
            fwrite($handle, json_encode($scope + ['owner' => $owner, 'expire' => time() + (($scope['kind'] ?? '') === 'websocket' ? 30 : self::limit('MEDIA_TICKET_SECONDS', 3600))], JSON_THROW_ON_ERROR));
        } finally { fclose($handle); }
        return $ticket;
    }

    public static function readTicket($ticket, bool $consume = false): array
    {
        if (!is_string($ticket) || !preg_match('/^[a-f0-9]{64}$/D', $ticket)) return [];
        $file = self::ticketDirectory() . '/' . $ticket . '.json';
        if (is_link($file)) return [];
        $handle = @fopen($file, 'r');
        if ($handle === false) return [];
        try {
            clearstatcache(true, $file);
            if (!flock($handle, ($consume ? LOCK_EX : LOCK_SH) | LOCK_NB) || !file_exists($file)) return [];
            $record = json_decode((string) stream_get_contents($handle), true);
            if ($consume) unlink($file);
            if (!is_array($record) || ($record['expire'] ?? 0) <= time() || !self::validToken($record['owner'] ?? '')) return [];
            return $record;
        } finally { fclose($handle); }
    }

    public static function ownsSocket(string $owner, $socket): bool
    {
        return is_string($socket) && $owner !== '' && ($socket === $owner
            || (str_starts_with($socket, $owner . '-') && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', substr($socket, strlen($owner) + 1)) === 1));
    }

    public static function terminalAllowed(string $token): bool
    {
        $record = self::record($token);
        return $record !== null && ($record['terminal'] ?? !array_key_exists('allowedRoots', $record)) === true;
    }

    public static function socketOwner(string $socket): string
    {
        $matches = [];
        foreach (self::tokens() as $token => $_) if (is_string($token) && self::ownsSocket($token, $socket)) $matches[] = $token;
        return count($matches) === 1 ? $matches[0] : '';
    }

    public static function bridgeSecret(): string
    {
        $secret = getenv('FILEMANAGER_BRIDGE_SECRET') ?: @file_get_contents(self::dataPath('bridge-secret', appController::baseDir() . '.runtime/bridge-secret'));
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
