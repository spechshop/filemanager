<?php

namespace plugins\Request;

use plugins\Extension\utilsFunction;
use plugins\Start\cache;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
class syncPath
{
    public static function api(Request $request, Response $response) {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $pathf = $request->post['path'] ?? $request->get['path'] ?? securityPolicy::initialPath(securityPolicy::requestToken($request));
        if ($pathf === '') $pathf = securityPolicy::initialPath(securityPolicy::requestToken($request));
        $pathf = securityPolicy::path($pathf, securityPolicy::requestToken($request));
        if (is_file($pathf)) {
            $pathf = pathinfo($pathf, PATHINFO_DIRNAME);
        }

        $owner = securityPolicy::requestToken($request);
        if (($request->get['media'] ?? '') === 'recursive') {
            if (($request->get['stream'] ?? '') === '1') {
                $response->header('Content-Type', 'application/x-ndjson; charset=utf-8');
                $response->header('Cache-Control', 'no-store');
                $response->header('X-Accel-Buffering', 'no');
                try {
                    foreach (self::mediaBatches($pathf, $owner, ($request->get['scope'] ?? 'recursive') !== 'current') as $batch) {
                        if ($response->write(json_encode(['success' => true] + $batch,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n") === false) return true;
                    }
                } catch (\Throwable $error) {
                    $response->write(json_encode(['success' => false, 'done' => true, 'message' => 'A busca foi interrompida.']) . "\n");
                }
                return $response->end();
            }
            $response->header('Content-Type', 'application/json');
            return $response->end(json_encode(['success' => true, 'currentPath' => $pathf]
                + self::mediaFiles($pathf, $owner), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $dataEached = [];
        foreach (safeFiles::listing($pathf, $owner, false, true) as $entry) {
            $file = $entry['path'];
            $type = $entry['directory'] ? 'folder' : pathinfo($file, PATHINFO_EXTENSION);
            $dataEached[] = [
                'name' => $entry['name'] . ($entry['directory'] && $entry['count'] !== null ? sprintf(' (%d ite%s)', $entry['count'], $entry['count'] === 1 ? 'm' : 'ns') : ''), 'path' => $file,
                'isImage' => utilsFunction::isMediaFile($file),
                'isMedia' => utilsFunction::isMovie(pathinfo($file, PATHINFO_EXTENSION)),
                'type' => $type, 'typeFile' => $type,
                'size' => $entry['size'] === null ? 'N/A' : utilsFunction::formatBytes($entry['size']),
                'lastModified' => date('d/m/Y H:i:s', (int) $entry['mtime']),
                'lastAccessed' => date('Y-m-d H:i:s', (int) $entry['atime']),
                'created' => date('Y-m-d H:i:s', (int) $entry['ctime']),
                'permissions' => $entry['permissions'], 'compress' => utilsFunction::isCompressedFile($file),
            ];
        }
        $newData = [];
        foreach ($dataEached as $item) {
            if ($item['type'] == 'folder') {
                $newData[] = $item;
            }
        }
        foreach ($dataEached as $item) {
            if ($item['type'] != 'folder') {
                $newData[] = $item;
            }
        }
        $responsejson = json_encode([
            'success' => true,
            'currentPath' => $pathf,
            'information' => $newData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!$responsejson) {
            // corrige o erro de codificação
        }
        return $response->end($responsejson);
    }

    /** Discover media below this folder, without following links to parents or siblings. */
    public static function mediaFiles(string $path, string $owner): array
    {
        $files = []; $truncated = false;
        foreach (self::mediaBatches($path, $owner) as $batch) {
            array_push($files, ...$batch['information']);
            $truncated = $batch['truncated'] ?? false;
        }
        return ['information' => $files, 'truncated' => $truncated];
    }

    /** Send small batches while scanning, so the first folder is usable immediately. */
    public static function mediaBatches(string $path, string $owner, bool $recursive = true): \Generator
    {
        $root = securityPolicy::path($path, $owner);
        $prefix = rtrim($root, '/') . '/';
        $pending = [$root]; $seen = []; $files = []; $examined = 0; $batch = []; $skipped = 0;
        $metadata = []; $folders = [];
        $deadline = microtime(true) + securityPolicy::limit('GALLERY_SCAN_SECONDS', 60);
        yield ['information' => [], 'scannedFolders' => 0, 'pendingFolders' => 1, 'done' => false];
        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                yield ['information' => $batch, 'metadata' => $metadata, 'folders' => $folders, 'done' => true, 'truncated' => true, 'skippedFolders' => $skipped];
                return;
            }
            $directory = array_pop($pending);
            try {
                $real = securityPolicy::path($directory, $owner);
                if (($real !== $root && !str_starts_with($real, $prefix)) || isset($seen[$real])) continue;
                $seen[$real] = true;
                $entries = safeFiles::listing($real, $owner);
            } catch (\DomainException $error) {
                if ($directory === $root || !securityPolicy::validToken($owner)) throw $error;
                ++$skipped;
                continue;
            }
            foreach ($entries as $entry) {
                if (++$examined > 50000 || count($files) >= 10000 || microtime(true) >= $deadline) {
                    yield ['information' => $batch, 'metadata' => $metadata, 'folders' => $folders, 'done' => true, 'truncated' => true, 'skippedFolders' => $skipped];
                    return;
                }
                if ($entry['directory']) {
                    try { $directoryPath = securityPolicy::path($entry['path'], $owner); } catch (\DomainException $error) { continue; }
                    if (str_starts_with($directoryPath, $prefix)) { $folders[] = $directoryPath; if ($recursive) $pending[] = $directoryPath; }
                    continue;
                }
                $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'flac'], true)) continue;
                try { $file = securityPolicy::path($entry['path'], $owner); }
                catch (\DomainException $error) {
                    if (!securityPolicy::validToken($owner)) throw $error;
                    continue;
                }
                if (str_starts_with($file, $prefix) && !isset($files[$file])) {
                    $files[$file] = true;
                    $batch[] = $file;
                    $metadata[$file] = ['mtime' => $entry['mtime'] ?? null, 'size' => $entry['size'] ?? null];
                }
                if (count($batch) >= 200) {
                    yield ['information' => $batch, 'metadata' => $metadata, 'folders' => $folders, 'scannedFolders' => count($seen), 'pendingFolders' => count($pending), 'done' => false];
                    $batch = []; $metadata = []; $folders = [];
                }
            }
            yield ['information' => $batch, 'metadata' => $metadata, 'folders' => $folders, 'scannedFolders' => count($seen), 'pendingFolders' => count($pending), 'done' => false];
            $batch = []; $metadata = []; $folders = [];
        }
        yield ['information' => $batch, 'metadata' => $metadata, 'folders' => $folders, 'done' => true, 'truncated' => false, 'skippedFolders' => $skipped];
    }
}
