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
}