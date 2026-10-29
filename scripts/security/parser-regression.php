<?php
declare(strict_types=1);
namespace Swoole\Http {
    class Request {
        public array $get = []; public array $post = []; public array $header = []; public array $server = ['path_info' => '/unrelated']; public string $body = '';
        public function rawContent(): string { return $this->body; }
    }
    class Response {
        public int $code = 200; public string $body = '';
        public function header($key, $value): void {}
        public function status($code): void { $this->code = $code; }
        public function end($value = ''): bool { $this->body = $value; return true; }
    }
}
namespace {
    if (extension_loaded('swoole') || !extension_loaded('tokenizer')) throw new \RuntimeException('Conventional PHP with Tokenizer required');
    $root = dirname(__DIR__, 2); require $root . '/vendor/autoload.php';
    foreach (['appController', 'securityPolicy', 'security', 'safeFiles'] as $component) require $root . '/plugins/Request/components/' . $component . '.php';
    require $root . '/plugins/Request/apps/phpParser.php';
    $tmp = sys_get_temp_dir() . '/fm-parser-' . bin2hex(random_bytes(10));
    mkdir($tmp, 0700); mkdir($tmp . '/projects'); mkdir($tmp . '/independent'); mkdir($tmp . '/outside');
    putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
    file_put_contents($tmp . '/tokens.json', json_encode(['fixture' => ['expire' => time() + 60, 'allowedRoots' => [$tmp . '/projects', $tmp . '/independent']]]));
    file_put_contents($tmp . '/independent/parser.php', '<?php $fixture = new \stdClass();');
    file_put_contents($tmp . '/outside/parser.php', '<?php $private = new \stdClass();');
    symlink($tmp . '/independent', $tmp . '/projects/authorized-link');
    try {
        foreach ([$tmp . '/independent/parser.php', $tmp . '/projects/authorized-link/parser.php'] as $path) {
            $request = new \Swoole\Http\Request(); $response = new \Swoole\Http\Response();
            $request->header = ['authorization' => 'Bearer fixture']; $request->body = json_encode(['nameFile' => $path]);
            \plugins\Request\phpParser::api($request, $response);
            if ($response->code !== 200 || (json_decode($response->body, true)['fixture']['class'] ?? '') !== 'stdClass') throw new \RuntimeException('Authorized parser path failed');
        }
        $request->body = json_encode(['nameFile' => $tmp . '/outside/parser.php']); $response = new \Swoole\Http\Response();
        \plugins\Request\phpParser::api($request, $response);
        if ($response->code !== 403 || str_contains($response->body, 'private')) throw new \RuntimeException('Parser escaped scope');
        echo "PASS actual phpParser handles independent authorized paths and links, and denies paths outside both roots\n";
    } finally {
        $clean = function ($path) use (&$clean) { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name); rmdir($path); }; $clean($tmp);
    }
}
