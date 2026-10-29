<?php
declare(strict_types=1);

// Run in a fresh runtime without a preceding Co\run: that used to mask the
// startup failure. Both output pipes must drain even across an unrelated signal.
require dirname(__DIR__, 2) . '/plugins/Extension/plugins/terminal.php';
Swoole\Runtime::enableCoroutine(0);
$signals = 0;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use (&$signals): void { $signals++; }, false);
$cli = new class {
    public function color(string $output, string $color): string { return $output; }
};
$parent = getmypid();
$pid = null;
$script = <<<'PHP'
usleep(100000);
posix_kill((int) $argv[1], SIGUSR1);
usleep(100000);
for ($i = 0; $i < 128; $i++) {
    fwrite(STDOUT, str_repeat('O', 8192));
    fwrite(STDERR, str_repeat('E', 8192));
}
PHP;
ob_start();
plugins\terminal::asyncShell([PHP_BINARY, '-r', $script, (string) $parent], $cli, $pid);
$output = ob_get_clean();
if ($signals !== 1 || $pid !== null
    || substr_count($output, 'O') !== 1048576 || substr_count($output, 'E') !== 1048576) {
    throw new RuntimeException('Interrupted select lost output or left a published child PID');
}
if (pcntl_waitpid(-1, $status, WNOHANG) !== -1 || pcntl_get_last_error() !== PCNTL_ECHILD) {
    throw new RuntimeException('Supervisor failed to reap its child');
}
if (plugins\terminal::pKill('1; touch /tmp/invalid-kill') || plugins\terminal::pKill(1)) {
    throw new RuntimeException('Unsafe process identifier accepted');
}
echo "PASS native supervisor starts without scheduler, drains stdout/stderr across EINTR, reaps child and validates PIDs\n";
