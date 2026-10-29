<?php
declare(strict_types=1);
if (!extension_loaded('zip')) { fwrite(STDERR, "REQUIRED: ext-zip, no test executed\n"); exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/plugins/Request/components/appController.php';
require $root . '/plugins/Request/components/securityPolicy.php';
require $root . '/plugins/Request/components/safeFiles.php';
use plugins\Request\securityPolicy;
use plugins\Request\safeFiles;
$tmp = sys_get_temp_dir() . '/fm-archive-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700); mkdir($tmp . '/files'); mkdir($tmp . '/outside');
$token = 'archive-fixture';
file_put_contents($tmp . '/tokens', json_encode([$token => ['expire' => time() + 3600, 'allowedRoots' => [$tmp . '/files']]]));
putenv('FILEMANAGER_TEMP_DIR=' . $tmp . '/private');
putenv('FILEMANAGER_ALLOWED_ROOTS=' . json_encode([$tmp . '/files'])); putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens');
$fail = 0; $count = 0;
function check($ok, $name) { global $count, $fail; $count++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }
try {
    foreach (['../outside/pwned', $tmp . '/outside/absolute-pwned', 'safe/../../outside/pwned', 'link'] as $name) {
        $zip = new ZipArchive(); $file = $tmp . '/files/attack.zip';
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('first-safe', 'must-not-be-written');
        $zip->addFromString($name, 'attack');
        if ($name === 'link') $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $denied = false;
        try { safeFiles::extract($file, $tmp . '/files/output', $token); }
        catch (Throwable $e) { $denied = true; }
        check($denied && !file_exists($tmp . '/files/output') && !file_exists($tmp . '/outside/pwned') && !file_exists($tmp . '/outside/absolute-pwned'), 'malicious late ZIP entry rejected before any extraction: ' . $name);
    }
    $tarHeader = function(string $name, string $content, string $type = '0', string $link = ''): string {
        $header = str_pad($name, 100, "\0") . "0000644\0" . "0000000\0" . "0000000\0"
            . sprintf('%011o', strlen($content)) . "\0" . "00000000000\0" . str_repeat(' ', 8)
            . $type . str_pad($link, 100, "\0") . "ustar\0" . '00' . str_repeat("\0", 249);
        $sum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
        return $header . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
    };
    foreach ([['../outside/pwned', '0', ''], ['bad-link', '2', $tmp . '/outside'], ['bad-hardlink', '1', '../outside/pwned']] as [$name, $type, $link]) {
        $file = $tmp . '/files/attack-' . $type . '.tar';
        file_put_contents($file, $tarHeader('first-safe', 'SAFE') . $tarHeader($name, $type === '0' ? 'ATTACK' : '', $type, $link) . str_repeat("\0", 1024));
        $denied = false;
        try { safeFiles::extract($file, $tmp . '/files/tar-attack-output', $token); } catch (Throwable $e) { $denied = true; }
        check($denied && !file_exists($tmp . '/files/tar-attack-output') && !file_exists($tmp . '/outside/pwned'), 'TAR traversal/link rejected before writing earlier safe entry: ' . $type);
    }
    // Existing destination symlink must not be followed even for a harmless member.
    mkdir($tmp . '/files/output'); symlink($tmp . '/outside', $tmp . '/files/output/link');
    $zip = new ZipArchive(); $file = $tmp . '/files/symlink.zip'; $zip->open($file, ZipArchive::CREATE); $zip->addFromString('link/pwned', 'attack'); $zip->close();
    $denied = false;
    try { safeFiles::extract($file, $tmp . '/files/output', $token); } catch (Throwable $e) { $denied = true; }
    check($denied && !file_exists($tmp . '/outside/pwned'), 'existing symlink target unchanged');
    $zip = new ZipArchive(); $file = $tmp . '/files/valid.zip'; $zip->open($file, ZipArchive::CREATE); $zip->addFromString('nested/valid.txt', 'OK'); $zip->close();
    safeFiles::extract($file, $tmp . '/files/valid-output', $token);
    check(file_get_contents($tmp . '/files/valid-output/nested/valid.txt') === 'OK', 'authorized ZIP extraction preserved');
    safeFiles::compress([$tmp . '/files/valid-output'], $tmp . '/files/generated.zip', $token);
    $generated = new ZipArchive(); $generated->open($tmp . '/files/generated.zip');
    check($generated->getFromName('valid-output/nested/valid.txt') === 'OK', 'authorized directory compression preserved through private snapshots');
    $generated->close();
    $tar = new PharData($tmp . '/files/valid.tar'); $tar->addFromString('nested/valid.txt', 'TAR-OK'); unset($tar);
    safeFiles::extract($tmp . '/files/valid.tar', $tmp . '/files/tar-output', $token);
    check(file_get_contents($tmp . '/files/tar-output/nested/valid.txt') === 'TAR-OK', 'authorized TAR extraction preserved');
    // These decoders are required for this suite, not silently skipped.
    $run = function(array $args, string $cwd, string $output = '/dev/null'): void {
        $pipes = [];
        $process = proc_open($args, [['file', '/dev/null', 'r'], ['file', $output, 'w'], ['file', '/dev/null', 'w']], $pipes, $cwd);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Required archive decoder failed: ' . $args[0]);
    };
    $run(['xz', '--compress', '--stdout', '--', $tmp . '/files/valid.tar'], $tmp, $tmp . '/files/valid.tar.xz');
    safeFiles::extract($tmp . '/files/valid.tar.xz', $tmp . '/files/xz-output', $token);
    check(file_get_contents($tmp . '/files/xz-output/nested/valid.txt') === 'TAR-OK', 'authorized TAR.XZ extraction preserved without filesystem extractor');
    $run(['7z', 'a', '-t7z', '--', $tmp . '/files/valid.7z', 'nested/valid.txt'], $tmp . '/files/valid-output');
    safeFiles::extract($tmp . '/files/valid.7z', $tmp . '/files/seven-output', $token);
    check(file_get_contents($tmp . '/files/seven-output/nested/valid.txt') === 'OK', 'authorized 7z extraction preserved via stdout decoder');
    $fixture = file_get_contents(__DIR__ . '/fixtures/rar-basic.uu');
    $fixture = substr($fixture, strpos($fixture, "\n") + 1); $fixture = substr($fixture, 0, strrpos($fixture, 'end'));
    file_put_contents($tmp . '/files/valid.rar', convert_uudecode($fixture));
    safeFiles::extract($tmp . '/files/valid.rar', $tmp . '/files/rar-output', $token);
    check(file_get_contents($tmp . '/files/rar-output/test.txt') === "test text document\r\n" && !is_link($tmp . '/files/rar-output/testlink'), 'authorized RAR preserved; decoder never creates links');
    mkdir($tmp . '/files/seven-link-input'); symlink($tmp . '/outside', $tmp . '/files/seven-link-input/link');
    $run(['7z', 'a', '-snl', '-t7z', '--', $tmp . '/files/link.7z', 'link'], $tmp . '/files/seven-link-input');
    $denied = false;
    try { safeFiles::extract($tmp . '/files/link.7z', $tmp . '/files/seven-link-output', $token); } catch (Throwable $e) { $denied = true; }
    check($denied && !file_exists($tmp . '/files/seven-link-output') && !file_exists($tmp . '/outside/pwned'), '7z symlink metadata rejected before filesystem writes');

} finally {
    $clean = function($path) use (&$clean) { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name); rmdir($path); }; $clean($tmp);
}
echo "$count assertions, $fail failures\n"; exit($fail ? 1 : 0);
