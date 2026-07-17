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
    private static function getDirSize($path) {
        if (!isset($GLOBALS['du_cache'])) {
            $GLOBALS['du_cache'] = [];
        }

        $now = time();
        $cacheKey = md5($path);
        $dirMtime = @filemtime($path);

        // Verifica se existe cache e se tem menos de 2 minutos e mtime não mudou
        if (isset($GLOBALS['du_cache'][$cacheKey]) &&
            ($now - $GLOBALS['du_cache'][$cacheKey]['time']) < 120 &&
            $GLOBALS['du_cache'][$cacheKey]['mtime'] === $dirMtime) {
            return $GLOBALS['du_cache'][$cacheKey]['size'];
        }

        // Executa du com abordagem de coroutines + Channel para timeout de 5s
        // Se demorar mais, pula (retorna 0, não cacheia timeout)
        $chan = new Channel(1);
        Coroutine::create(function () use ($path, $chan) {
            $command = 'du -sb ' . escapeshellarg($path) . ' 2>/dev/null';
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            $chan->push(['output' => $output, 'returnVar' => $returnVar]);
        });
        $result = $chan->pop(2.0); // timeout de 5 segundos via coroutine Channel
        $size = 0;
        $timedOut = ($result === false);

        if (!$timedOut && !empty($result['output'])) {
            $dush = implode("\n", $result['output']);
            $parts = explode("\t", trim($dush));
            $size = isset($parts[0]) ? (int)$parts[0] : 0;
        }

        if (!$timedOut) {
            $GLOBALS['du_cache'][$cacheKey] = [
                'size' => $size,
                'time' => $now,
                'mtime' => $dirMtime
            ];
        }

        return $size;
    }

    public static function api(Request $request, Response $response) {
        $_GET = $request->get;
        $_POST = $request->post;
        $response->header('Content-Type', 'application/json');
        if (!empty($_GET['tokenBrowser'])) {
            $tokenBrowser = $_GET['tokenBrowser'];
        }
        if (!empty($_POST['tokenBrowser'])) {
            $tokenBrowser = $_POST['tokenBrowser'];
        }
        if (!empty($_GET['path'])) {
            $pathf = $_GET['path'];
        }
        if (!empty($_POST['path'])) {
            $pathf = $_POST['path'];
        }
        if (empty($tokenBrowser)) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Identifier not found',
            ]));
        } elseif (!key_exists($tokenBrowser, cache::global()['dataKeys'])) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'UniqueId not found',
            ]));
        } elseif (strtotime(date('Y-m-d H:i:s')) >= cache::global()['dataKeys'][$tokenBrowser]['expire']) {
            return $response->end(json_encode([
                'success' => false,
                'message' => 'Your plan as has expired. Contact support for more information.',
            ]));
        } elseif (empty($pathf)) {
            $pathf = '';
        }
        if (!is_dir('files')) {
            mkdir('files');
        }
        if (is_file($pathf)) {
            $pathf = pathinfo($pathf, PATHINFO_DIRNAME);
        }

        $folder = appController::listFilesAndDirs($pathf);
        $dataEached = [];

        if (!empty($folder)) {
            $maxConcurrent = 10;
            $sem = new Channel($maxConcurrent);
            $resultChan = new Channel(count($folder));

            foreach ($folder as $file) {
                $sem->push(true);
                Coroutine::create(function () use ($file, $resultChan, $sem) {
                    $isDirectory = is_dir($file);

                    if ($isDirectory) {
                        $typeFile = 'folder';
                        $size = utilsFunction::formatBytes(self::getDirSize($file));
                    } else {
                        $typeFile = pathinfo($file, PATHINFO_EXTENSION);
                        $size = utilsFunction::formatBytes(filesize($file));
                    }

                    $path = str_replace('//', '/', $file);
                    $namefile = substr(basename($file), 0, 35);
                    $itemCount = $isDirectory ? utilsFunction::countItensInPath($file) : 0;
                    $item = [
                        'name' => htmlspecialchars(basename($namefile) . ($isDirectory ? sprintf(' (%s ite%s)', $itemCount, $itemCount > 1 ? 'ns' : 'm') : '')),
                        'path' => $path,
                        'isImage' => utilsFunction::isMediaFile($file),
                        'isMedia' => utilsFunction::isMovie(pathinfo($file, PATHINFO_EXTENSION)),
                        'type' => $typeFile,
                        'size' => $size,
                        'lastModified' => date('d/m/Y H:i:s', filemtime($file)),
                        'lastAccessed' => date('Y-m-d H:i:s', fileatime($file)),
                        'created' => date('Y-m-d H:i:s', filectime($file)),
                        'typeFile' => $typeFile,
                        'permissions' => $isDirectory ? 'drwxr-xr-x' : utilsFunction::getFilePermissions($file),
                        'compress' => utilsFunction::isCompressedFile($file),
                    ];
                    $resultChan->push($item);
                    $sem->pop();
                });
            }

            for ($i = 0; $i < count($folder); $i++) {
                $dataEached[] = $resultChan->pop();
            }
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
            'information' => $newData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!$responsejson) {
            // corrige o erro de codificação
        }
        return $response->end($responsejson);
    }
}