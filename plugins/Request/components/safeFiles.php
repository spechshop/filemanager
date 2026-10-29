<?php
declare(strict_types=1);
namespace plugins\Request;

final class safeFiles
{
    private static array $active = [];
    private static int $total = 0;

    /** @return array{owner: string, process: resource, stream: resource, input: resource, size: int, entries: array, result: array} */
    private static function start(string $op, string $path, string $token, ?string $target = null, int $offset = 0, ?int $length = null, bool $details = false, array $parameters = []): array
    {
        if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
        $command = json_encode(['op' => $op, 'path' => $path, 'target' => $target, 'offset' => $offset, 'length' => $length, 'token' => $token,
            'fallbackRoots' => securityPolicy::adminAllowed($token) ? [] : securityPolicy::roots($token), 'uploadDirectory' => securityPolicy::uploadDirectory(),
            'directoryStatsSeconds' => $details ? securityPolicy::limit('DIRECTORY_STATS_SECONDS', 2) : 0,
            'tokenFile' => securityPolicy::tokenFile(), 'base' => appController::baseDir(),
            'trashDirectory' => securityPolicy::dataPath('trash', appController::baseDir() . '.runtime/trash')] + $parameters, JSON_THROW_ON_ERROR) . "\n";
        if ((self::$active[$token] ?? 0) >= securityPolicy::limit('FILESYSTEM_PER_USER', 8) || self::$total >= securityPolicy::limit('FILESYSTEM_TOTAL', 32)) throw new \DomainException('Filesystem quota exceeded');
        self::$active[$token] = (self::$active[$token] ?? 0) + 1; self::$total++;
        $pipes = [];
        $process = \proc_open([getenv('FILEMANAGER_PYTHON') ?: '/usr/bin/python3', '-I', appController::baseDir() . 'scripts/security/fs-broker.py'],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) { self::release($token); throw new \RuntimeException('Secure filesystem helper unavailable'); }
        stream_set_timeout($pipes[1], securityPolicy::limit('FILESYSTEM_TIMEOUT_SECONDS', 60));
        fwrite($pipes[0], $command);
        fflush($pipes[0]);
        $line = fgets($pipes[1]);
        $result = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            proc_terminate($process); fclose($pipes[0]); fclose($pipes[1]); proc_close($process); self::release($token);
            throw new \DomainException('Secure filesystem operation denied');
        }
        return ['owner' => $token, 'process' => $process, 'stream' => $pipes[1], 'input' => $pipes[0],
            'size' => $result['size'] ?? 0, 'entries' => $result['entries'] ?? [], 'result' => $result['result'] ?? []];
    }

    private static function release(string $owner): void
    {
        self::$active[$owner]--; self::$total--;
        if (self::$active[$owner] === 0) unset(self::$active[$owner]);
    }

    public static function close(array $operation, bool $cancel = false): void
    {
        if ($cancel) proc_terminate($operation['process']);
        if (is_resource($operation['input'])) fclose($operation['input']);
        if (is_resource($operation['stream'])) fclose($operation['stream']);
        try {
            if (proc_close($operation['process']) !== 0 && !$cancel) throw new \DomainException('Secure filesystem operation interrupted');
        } finally { self::release($operation['owner']); }
    }

    public static function upload(string $source, string $target, string $token): void { self::close(self::start('upload', $source, $token, $target)); }

    /** @param list<array{source: string, target: string}> $uploads */
    public static function uploadMany(array $uploads, string $token, ?string $base = null, array $directories = []): void
    {
        if ($uploads === [] && $directories === []) return;
        self::close(self::start('upload-many', '', $token, parameters: ['uploads' => $uploads, 'uploadBase' => $base, 'directories' => $directories]));
    }

    public static function reader(string $path, string $token, int $offset = 0, ?int $length = null): array { return self::start('read', $path, $token, null, $offset, $length); }

    public static function read(string $path, string $token): string
    {
        $operation = self::reader($path, $token);
        try { return (string) stream_get_contents($operation['stream']); }
        finally { self::close($operation); }
    }

    public static function writeStream(string $path, $input, string $token): void
    {
        $operation = self::start('write', $path, $token);
        try {
            if (stream_copy_to_stream($input, $operation['input']) === false) throw new \RuntimeException('Write failed');
        } finally { self::close($operation); }
    }

    public static function write(string $path, string $data, string $token): bool
    {
        $input = fopen('php://temp', 'w+b');
        try { fwrite($input, $data); rewind($input); self::writeStream($path, $input, $token); }
        finally { fclose($input); }
        return true;
    }

    public static function listing(string $path, string $token, bool $strict = false, bool $details = false): array
    {
        $operation = self::start('list', $path, $token, null, 0, null, $details);
        try {
            if ($strict) foreach ($operation['entries'] as $entry) if (!empty($entry['denied'])) throw new \DomainException('unsafe link');
            return array_values(array_filter($operation['entries'], static fn(array $entry): bool => empty($entry['denied'])));
        }
        finally { self::close($operation); }
    }

    public static function mkdir(string $path, string $token): bool
    {
        self::close(self::start('mkdir', $path, $token)); return true;
    }

    public static function remove(string $path, string $token): void
    {
        self::close(self::start('remove', $path, $token));
    }

    public static function trash(string $action, string $token, string $path = '', string $id = '', bool $rename = false): array
    {
        if (!in_array($action, ['put', 'list', 'restore', 'purge'], true)) throw new \DomainException('trash action');
        $operation = self::start('trash-' . $action, $path, $token, parameters: ['id' => $id, 'rename' => $rename]);
        try { return $operation['result']; }
        finally { self::close($operation); }
    }

    public static function copy(string $source, string $target, string $token): void
    {
        self::close(self::start('copy', $source, $token, $target));
    }

    public static function move(string $source, string $target, string $token): bool
    {
        self::close(self::start('move', $source, $token, $target)); return true;
    }

    /** Private immutable pathname for libraries which cannot consume an open stream. */
    public static function snapshot(string $path, string $token, string $suffix = ''): string
    {
        $temporary = securityPolicy::temporary('fm-snapshot-', $suffix);
        $output = fopen($temporary, 'wb');
        $operation = null;
        try {
            $operation = self::reader($path, $token);
            stream_copy_to_stream($operation['stream'], $output);
        } catch (\Throwable $e) { unlink($temporary); throw $e; }
        finally { fclose($output); if ($operation !== null) self::close($operation); }
        return $temporary;
    }

    public static function walk(string $path, string $token, array $seen = []): \Generator
    {
        $real = securityPolicy::path($path, $token);
        if (isset($seen[$real])) throw new \DomainException('recursive link');
        $seen[$real] = true;
        foreach (self::listing($path, $token, true) as $entry) {
            yield $entry;
            if ($entry['directory']) yield from self::walk($entry['path'], $token, $seen);
        }
    }

    /** Source is a private Git staging tree, never a client-supplied path. */
    public static function publishGit(string $staging, string $destination, string $token): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        // Reject only links whose eventual checkout target would escape the scope.
        foreach ($iterator as $item) if ($item->isLink()) {
            $relative = substr($item->getPathname(), strlen($staging) + 1);
            $link = readlink($item->getPathname());
            securityPolicy::path(str_starts_with($link, '/') ? $link : dirname($destination . '/' . $relative) . '/' . $link, $token);
        }
        self::mkdir($destination, $token);
        foreach ($iterator as $item) {
            $target = $destination . '/' . substr($item->getPathname(), strlen($staging) + 1);
            if ($item->isLink()) self::close(self::start('symlink', $target, $token, readlink($item->getPathname())));
            elseif ($item->isDir()) self::mkdir($target, $token);
            elseif ($item->isFile()) {
                $input = fopen($item->getPathname(), 'rb');
                try { self::writeStream($target, $input, $token); } finally { fclose($input); }
            } else throw new \DomainException('git file type');
        }
    }

    public static function mkdirParents(string $path, string $token): void
    {
        if (is_dir($path)) return;
        self::mkdirParents(dirname($path), $token);
        self::mkdir($path, $token);
    }

    public static function compress(array $paths, string $destination, string $token): bool
    {
        $temporary = securityPolicy::temporary('fm-zip-');
        $zip = new \ZipArchive(); $snapshots = [];
        try {
            if ($zip->open($temporary, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('archive');
            $add = function (string $path, string $name, array $seen = []) use (&$add, &$snapshots, $zip, $token): void {
                $path = securityPolicy::path($path, $token);
                if (isset($seen[$path])) throw new \DomainException('recursive link');
                $seen[$path] = true;
                if (is_dir($path)) {
                    $zip->addEmptyDir($name);
                    foreach (self::listing($path, $token) as $item) $add($item['path'], $name . '/' . $item['name'], $seen);
                } else {
                    $snapshot = self::snapshot($path, $token); $snapshots[] = $snapshot;
                    $zip->addFile($snapshot, $name);
                }
            };
            foreach ($paths as $path) $add($path, basename($path));
            $zip->close();
            $input = fopen($temporary, 'rb');
            try { self::writeStream($destination, $input, $token); }
            finally { fclose($input); }
            return true;
        } finally {
            foreach ($snapshots as $snapshot) @unlink($snapshot);
            @unlink($temporary);
        }
    }

    /** Read metadata for every entry before creating anything. Never extract links. */
    public static function extract(string $archive, string $target, string $token): array
    {
        $original = securityPolicy::path($archive, $token);
        $snapshot = self::snapshot($original, $token);
        $archive = $snapshot . '.' . preg_replace('/[^a-z0-9.]/i', '', basename($original));
        rename($snapshot, $archive);
        try { return self::extractSnapshot($archive, $target, $token); }
        finally { @unlink($archive); }
    }

    private static function extractSnapshot(string $archive, string $target, string $token): array
    {
        $target = securityPolicy::path($target, $token);
        $maximum = securityPolicy::limit('ARCHIVE_BYTES', 536870912);
        $temporary = null;
        if (preg_match('/\.xz$/iD', $archive)) {
            $temporary = securityPolicy::temporary('fm-xz-');
            try {
                $process = null;
                $input = self::decoder(['xz', '--decompress', '--stdout', '--', $archive], $process, $token);
                $output = fopen($temporary, 'wb');
                try {
                    stream_copy_to_stream($input, $output, $maximum + 1);
                    $size = ftell($output);
                } finally { fclose($input); fclose($output); }
                if (proc_close($process) !== 0 || $size > $maximum) throw new \DomainException('archive size');
                // PharData needs a TAR suffix; the file is private and never extracted by xz.
                rename($temporary, $temporary . '.tar'); $temporary .= '.tar';
                return self::extractDecoded($temporary, $target, $token);
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        return self::extractDecoded($archive, $target, $token);
    }

    /** Decoder subprocesses emit bytes only; they never receive a filesystem extraction directory. */
    private static function decoder(array $args, &$process, string $token)
    {
        if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
        $pipes = [];
        $process = proc_open($args, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => getenv('PATH'), 'LC_ALL' => 'C']);
        if (!is_resource($process)) throw new \RuntimeException('archive decoder');
        return $pipes[1];
    }

    private static function extractDecoded(string $archive, string $target, string $token): array
    {
        $entries = []; $total = 0;
        $maximum = securityPolicy::limit('ARCHIVE_BYTES', 536870912);
        $entryLimit = securityPolicy::limit('ARCHIVE_ENTRIES', 10000);
        $zip = null;
        if (strtolower(pathinfo($archive, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($archive, \ZipArchive::RDONLY) !== true) throw new \DomainException('archive');
            try {
                if ($zip->numFiles > $entryLimit) throw new \DomainException('archive size');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i); $opsys = 0; $attributes = 0;
                    $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                    $mode = ($attributes >> 16) & 0170000;
                    if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) throw new \DomainException('archive link');
                    $entries[] = ['name' => $stat['name'], 'size' => $stat['size'], 'dir' => str_ends_with($stat['name'], '/'), 'index' => $i];
                }
            } catch (\Throwable $e) { $zip->close(); throw $e; }
        } elseif (preg_match('/\.(7z|rar)$/iD', $archive)) {
            $process = null;
            $input = self::decoder(['7z', 'l', '-slt', '-ba', '--', $archive], $process, $token);
            try { $listing = stream_get_contents($input, 8388609); }
            finally { fclose($input); }
            if (proc_close($process) !== 0 || strlen($listing) > 8388608) throw new \DomainException('archive listing');
            foreach (preg_split('/\r?\n\r?\n/', trim($listing, "\r\n")) as $block) {
                $fields = [];
                foreach (preg_split('/\r?\n/', $block) as $line) {
                    $pair = explode(' = ', $line, 2);
                    if (count($pair) !== 2 || isset($fields[$pair[0]])) throw new \DomainException('archive metadata');
                    $fields[$pair[0]] = $pair[1];
                }
                if (!isset($fields['Path'], $fields['Size']) || !ctype_digit($fields['Size'])
                    || isset($fields['Symbolic Link']) || isset($fields['Hard Link'])
                    || preg_match('/(?:^| )l[rwx-]{9}/', $fields['Attributes'] ?? '')) throw new \DomainException('archive link');
                $entries[] = ['name' => $fields['Path'], 'size' => (int) $fields['Size'], 'dir' => ($fields['Folder'] ?? '') === '+' || str_starts_with($fields['Attributes'] ?? '', 'D'), 'decoder' => true];
                if (count($entries) > $entryLimit) throw new \DomainException('archive size');
            }
        } else {
            // PharData reads TAR and compressed TAR without invoking a filesystem extractor.
            if (!preg_match('/\.(tar|tgz|tar\.gz|tar\.bz2|gz|bz2)$/iD', $archive)) throw new \DomainException('archive format');
            $phar = new \PharData($archive);
            foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $key => $item) {
                if ($item->isLink() || (!$item->isFile() && !$item->isDir())) throw new \DomainException('archive link');
                $name = substr($key, strlen('phar://' . $archive . '/'));
                $entries[] = ['name' => $name, 'size' => $item->getSize(), 'dir' => $item->isDir(), 'source' => $key];
                if (count($entries) > $entryLimit) throw new \DomainException('archive size');
            }
        }
        try {
            foreach ($entries as &$entry) {
                $name = rtrim($entry['name'], '/');
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, ':') || in_array('..', explode('/', $name), true)) throw new \DomainException('archive path');
                $entry['target'] = securityPolicy::path($target . '/' . $name, $token, false);
                $total += $entry['size'];
                if ($total > $maximum) throw new \DomainException('archive size');
            }
            unset($entry);
            // Complete validation precedes mkdir, including for malicious late members.
            foreach ($entries as $entry) {
                if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
                $out = securityPolicy::path($entry['target'], $token, false);
                $parent = $entry['dir'] ? $out : dirname($out);
                self::mkdirParents($parent, $token);
                if ($entry['dir']) continue;
                $process = null;
                $input = isset($entry['decoder'])
                    ? self::decoder(['7z', 'x', '-so', '-spd', '--', $archive, $entry['name']], $process, $token)
                    : ($zip ? $zip->getStream($entry['name']) : fopen($entry['source'], 'rb'));
                if (!$input) throw new \RuntimeException('archive stream');
                $output = tmpfile();
                if (!$output) throw new \RuntimeException('archive temporary');
                try {
                    $written = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                    if ($written !== $entry['size']) throw new \DomainException('archive size');
                    rewind($output);
                    self::writeStream($out, $output, $token);
                } finally {
                    fclose($input); fclose($output);
                    if (is_resource($process) && proc_close($process) !== 0) throw new \DomainException('archive decoder');
                }
            }
        } finally { if ($zip) $zip->close(); }
        return ['success' => true, 'message' => 'Arquivo extraído com sucesso.'];
    }
}
