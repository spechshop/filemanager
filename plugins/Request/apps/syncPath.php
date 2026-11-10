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
        $root = securityPolicy::path($path, $owner);
        $prefix = rtrim($root, '/') . '/';
        $pending = [$root]; $seen = []; $files = []; $examined = 0;
        $deadline = microtime(true) + 15;
        while ($pending !== []) {
            if (microtime(true) >= $deadline) return ['information' => array_keys($files), 'truncated' => true];
            $directory = array_pop($pending);
            try {
                $real = securityPolicy::path($directory, $owner);
                if (($real !== $root && !str_starts_with($real, $prefix)) || isset($seen[$real])) continue;
                $seen[$real] = true;
                $entries = safeFiles::listing($real, $owner);
            } catch (\DomainException $error) {
                if ($directory === $root || !securityPolicy::validToken($owner)) throw $error;
                continue;
            }
            foreach ($entries as $entry) {
                if (++$examined > 50000 || count($files) >= 10000 || microtime(true) >= $deadline) {
                    return ['information' => array_keys($files), 'truncated' => true];
                }
                if ($entry['directory']) { $pending[] = $entry['path']; continue; }
                $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mp3', 'wav'], true)) continue;
                try { $file = securityPolicy::path($entry['path'], $owner); }
                catch (\DomainException $error) {
                    if (!securityPolicy::validToken($owner)) throw $error;
                    continue;
                }
                if (str_starts_with($file, $prefix)) $files[$file] = true;
            }
        }
        return ['information' => array_keys($files), 'truncated' => false];
    }
}
