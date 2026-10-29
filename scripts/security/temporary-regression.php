<?php
declare(strict_types=1);
namespace Swoole\Http {
    class Request {
        public array $get = []; public array $post = []; public array $header = [];
        public array $server = ['request_method' => 'POST']; public string $body = '';
        public function rawContent(): string { return $this->body; }
    }
    class Response {
        public int $code = 200; public string $body = '';
        public function header($key, $value): void {}
        public function status($value): void { $this->code = $value; }
        public function end($value = ''): bool { $this->body = $value; return true; }
    }
}
namespace {
    if (extension_loaded('swoole')) { fwrite(STDERR, "Conventional PHP with -n required\n"); exit(1); }
    if (($argv[1] ?? '') !== '--child') {
        $directory = sys_get_temp_dir() . '/fm-private-temporaries-' . bin2hex(random_bytes(10)); mkdir($directory, 0700);
        try {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-n', '-d', 'sys_temp_dir=' . $directory, __FILE__, '--child'],
                [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes);
            $status = is_resource($process) ? proc_close($process) : 1;
        } finally {
            $clean = function ($path) use (&$clean) { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name); rmdir($path); };
            $clean($directory);
        }
        exit($status);
    }
    $root = dirname(__DIR__, 2);
    foreach (['appController', 'securityPolicy', 'security', 'safeFiles'] as $component) require $root . '/plugins/Request/components/' . $component . '.php';
    require $root . '/plugins/Request/apps/formatCode.php'; require $root . '/plugins/Request/apps/phpstanCheck.php';
    $tmp = sys_get_temp_dir();
    putenv('FILEMANAGER_TEMP_DIR=' . $tmp);
    putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
    file_put_contents($tmp . '/tokens.json', json_encode(['fixture-admin' => ['expire' => time() + 60]]));
    $formatter = $tmp . '/synthetic-formatter.php';
    file_put_contents($formatter, '<?php file_put_contents($argv[2], "<?php // formatted fixture");');
    $method = new \ReflectionMethod(\plugins\Request\formatCode::class, 'runPhpCsFixer');
    $formatted = $method->invoke(null, $formatter, '<?php // original fixture', 'fixture-admin');
    if ($formatted !== '<?php // formatted fixture' || glob($tmp . '/csfix_*')) throw new \RuntimeException('Formatter temporary leaked or failed');
    putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
    file_put_contents($tmp . '/tokens.json', json_encode(['fixture-admin' => ['expire' => time() + 60]]));
    try {
        $method->invoke(null, $formatter, '<?php // revoked fixture', 'revoked-fixture');
        throw new \RuntimeException('Revoked formatter process started');
    } catch (\DomainException $expected) {
        if (glob($tmp . '/csfix_*')) throw new \RuntimeException('Revoked formatter leaked temporary');
    }
    $request = new \Swoole\Http\Request(); $response = new \Swoole\Http\Response();
    $request->header = ['authorization' => 'Bearer fixture-admin'];
    $request->body = json_encode(['code' => '<?php function fixtureTemporary(): int { return "wrong-type"; }']);
    \plugins\Request\phpstanCheck::api($request, $response);
    $result = json_decode($response->body, true);
    if ($response->code !== 200 || empty($result['diagnostics']) || glob($tmp . '/pstan_*') || glob($tmp . '/pstan_conf_*')) throw new \RuntimeException('PHPStan failed to analyze code or leaked temporaries');
    echo "PASS reserved formatter/PHPStan temporaries are cleaned; actual PHPStan detects the synthetic type error\n";
}
