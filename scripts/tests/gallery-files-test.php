<?php
// Run with php -n: real handlers and filesystem broker, isolated HTTP doubles.
namespace Swoole\Http {
    class Request {
        public array $get = [], $post = [], $header = ['host' => 'localhost'];
        public array $server = ['request_method' => 'GET'];
        public function rawContent(): string { return ''; }
    }
    class Response {
        public int $code = 200;
        public string $body = '';
        public function header($name, $value): void {}
        public function status($code): void { $this->code = $code; }
        public function write($body): bool { $this->body .= $body; return true; }
        public function end($body = ''): bool { $this->body .= $body; return true; }
    }
}
namespace {
    use plugins\Request\appController;
    use plugins\Request\safeFiles;

    $project = dirname(__DIR__, 2);
    foreach (['appController', 'securityPolicy', 'security', 'safeFiles'] as $component) require $project . '/plugins/Request/components/' . $component . '.php';
    foreach (['syncPath', 'imageBase64'] as $app) require $project . '/plugins/Request/apps/' . $app . '.php';
    $tmp = sys_get_temp_dir() . '/fm-gallery-' . bin2hex(random_bytes(10));
    mkdir($tmp, 0700);
    mkdir($tmp . '/files/album/nested/deeper', 0700, true);
    mkdir($tmp . '/files/sibling');
    mkdir($tmp . '/outside');
    putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
    putenv('FILEMANAGER_DATA_DIR=' . $tmp);
    putenv('FILEMANAGER_ORIGIN=http://localhost');
    putenv('FILEMANAGER_FILESYSTEM_PER_USER');
    putenv('FILEMANAGER_FILESYSTEM_TOTAL');
    $tokens = ['gallery-owner' => ['expire' => time() + 300, 'allowedRoots' => [$tmp . '/files']]];
    file_put_contents($tmp . '/tokens.json', json_encode($tokens));
    $checks = 0;
    function checkGallery(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
        ++$GLOBALS['checks'];
        echo "PASS $message\n";
    }
    function invokeGallery(string $route, array $query): \Swoole\Http\Response {
        $request = new \Swoole\Http\Request();
        $request->get = $query;
        $request->header['authorization'] = 'Bearer gallery-owner';
        $request->server['path_info'] = '/' . $route;
        $response = new \Swoole\Http\Response();
        appController::call($request, $response, '/' . $route);
        return $response;
    }
    $held = [];
    try {
        $expected = [];
        for ($i = 0; $i < 100; ++$i) {
            $file = $tmp . '/files/album/' . ($i < 50 ? '' : 'nested/deeper/') . "foto-$i.JPG";
            file_put_contents($file, 'gallery-image-' . $i);
            $expected[] = $file;
        }
        file_put_contents($tmp . '/files/album/readme.txt', 'not media');
        file_put_contents($tmp . '/files/sibling/secret.jpg', 'sibling');
        file_put_contents($tmp . '/outside/secret.jpg', 'outside');
        symlink($tmp . '/outside', $tmp . '/files/album/escape');
        symlink($tmp . '/files/sibling', $tmp . '/files/album/sibling-link');
        symlink($tmp . '/files/sibling/secret.jpg', $tmp . '/files/album/sibling-photo.jpg');
        symlink($tmp . '/files/album', $tmp . '/files/album/nested/loop');
        symlink($tmp . '/files/album/nested', $tmp . '/files/album/alias');
        symlink('missing', $tmp . '/files/album/broken.jpg');
        $response = invokeGallery('syncPath', ['path' => $tmp . '/files/album', 'media' => 'recursive']);
        checkGallery($response->code === 200, 'recursive gallery request succeeds with broken, escaping and circular links');
        $data = json_decode($response->body, true);
        sort($expected); sort($data['information']);
        checkGallery($data['information'] === $expected, 'all 100 photos are found across nested folders, without siblings, duplicates or text files');
        checkGallery($data['truncated'] === false, 'a 100-photo gallery is complete');
        $streamed = invokeGallery('syncPath', ['path' => $tmp . '/files/album', 'media' => 'recursive', 'stream' => '1']);
        $batches = array_map(static fn($line) => json_decode($line, true), explode("\n", trim($streamed->body)));
        checkGallery($streamed->code === 200 && count($batches) > 3 && $batches[0]['done'] === false && end($batches)['done'] === true,
            'streaming reports initial progress, folder results and explicit completion');
        $streamedFiles = [];
        foreach ($batches as $batch) array_push($streamedFiles, ...$batch['information']);
        sort($streamedFiles);
        checkGallery($streamedFiles === $expected, 'streamed and JSON gallery listings agree without duplicates');
        $generator = \plugins\Request\syncPath::mediaBatches($tmp . '/files/album', 'gallery-owner');
        $generator->rewind(); $generator->next();
        checkGallery(count($generator->current()['information']) === 50 && $generator->current()['done'] === false,
            'root photos are yielded before any descendant listing completes');
        unset($generator);
        checkGallery(invokeGallery('syncPath', ['path' => $tmp . '/outside', 'media' => 'recursive'])->code === 403, 'an unauthorized starting directory remains forbidden');

        // The old thumbnail queue issued unlimited transfers after tickets resolved.
        // Hold eight real readers to reproduce the resulting HTTP 403 at the ninth.
        for ($i = 0; $i < 8; ++$i) $held[] = safeFiles::reader($expected[$i], 'gallery-owner');
        checkGallery(invokeGallery('imageBase64', ['path' => $expected[8]])->code === 403, 'reproduced: exceeding eight simultaneous filesystem readers returns HTTP 403');
        foreach ($held as $operation) safeFiles::close($operation);
        $held = [];
        foreach (array_chunk($expected, 3) as $batch) {
            // Three preview transfers plus the active media leave spare slots.
            foreach ($batch as $file) $held[] = safeFiles::reader($file, 'gallery-owner');
            $response = invokeGallery('imageBase64', ['path' => $batch[0]]);
            if ($response->code !== 200 || $response->body !== file_get_contents($batch[0])) throw new \RuntimeException('bounded preview transfer failed');
            foreach ($held as $operation) {
                if (!str_starts_with(stream_get_contents($operation['stream']), 'gallery-image-')) throw new \RuntimeException('incomplete image');
                safeFiles::close($operation);
            }
            $held = [];
        }
        checkGallery(true, 'all 100 photos transfer successfully in bounded batches alongside the active media');
        $tokens['gallery-owner']['revoked'] = true;
        file_put_contents($tmp . '/tokens.json', json_encode($tokens));
        checkGallery(invokeGallery('syncPath', ['path' => $tmp . '/files/album', 'media' => 'recursive'])->code === 401, 'revoked credentials cannot enumerate gallery files');
        echo "$checks assertions passed\n";
    } finally {
        foreach ($held as $operation) safeFiles::close($operation, true);
        $clean = function (string $path) use (&$clean): void {
            if (is_link($path) || !is_dir($path)) { unlink($path); return; }
            foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name);
            rmdir($path);
        };
        $clean($tmp);
    }
}
