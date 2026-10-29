<?php
declare(strict_types=1);
// Real coroutine overlap verifies reservations before the first asynchronous IO.
if (!extension_loaded('swoole')) { fwrite(STDERR, "Swoole required\n"); exit(1); }
$root = dirname(__DIR__, 2);
foreach (['appController', 'securityPolicy', 'safeProcess'] as $component) require $root . '/plugins/Request/components/' . $component . '.php';
$directory = sys_get_temp_dir() . '/fm-auxiliary-quotas-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
putenv('FILEMANAGER_TOKENS_FILE=' . $directory . '/tokens.json');
putenv('FILEMANAGER_AUXILIARY_PER_USER=1');
putenv('FILEMANAGER_AUXILIARY_TOTAL=1');
file_put_contents($directory . '/tokens.json', json_encode([
    'fixture-one' => ['expire' => time() + 60], 'fixture-two' => ['expire' => time() + 60],
]));
$failures = [];
try {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Swoole\Coroutine\run(static function () use (&$failures): void {
        $done = new Swoole\Coroutine\Channel(1);
        Swoole\Coroutine::create(static function () use ($done, &$failures): void {
            try {
                $result = \plugins\Request\safeProcess::run(['/usr/bin/python3', '-c', 'import time; time.sleep(0.3); print("complete")'], 'fixture-one');
                if ($result['output'] !== "complete\n") $failures[] = 'legitimate output';
            } catch (Throwable $error) { $failures[] = 'legitimate tool: ' . get_class($error); }
            finally { $done->push(true); }
        });
        Swoole\Coroutine::sleep(0.05);
        foreach (['fixture-one', 'fixture-two'] as $owner) {
            try {
                \plugins\Request\safeProcess::run(['/usr/bin/true'], $owner);
                $failures[] = 'concurrent quota: ' . $owner;
            } catch (DomainException $expected) {
                if (!str_contains($expected->getMessage(), 'quota')) $failures[] = 'wrong denial';
            }
        }
        $done->pop(5);
        try {
            if (\plugins\Request\safeProcess::run(['/usr/bin/true'], 'fixture-two')['exit'] !== 0) $failures[] = 'released quota';
        } catch (Throwable $error) { $failures[] = 'quota not released'; }
    });
} finally {
    unlink($directory . '/tokens.json'); rmdir($directory);
}
if ($failures) { fwrite(STDERR, json_encode($failures) . "\n"); exit(1); }
echo "PASS actual coroutine overlap enforces owner/global auxiliary quotas and releases reservations\n";
