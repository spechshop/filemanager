<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/plugins/Request/components/privateJson.php';
use plugins\Request\privateJson;
if (($argv[1] ?? '') === 'child') {
    [$script, $mode, $file, $owner] = $argv;
    for ($i = 0; $i < 40; $i++) privateJson::update($file, static function (array $data) use ($owner, $i): array {
        $data[$owner][] = ['id' => 'session-' . $i, 'name' => 'fixture'];
        return $data;
    });
    exit;
}
$tmp = sys_get_temp_dir() . '/fm-metadata-' . bin2hex(random_bytes(10));
mkdir($tmp, 0700); $file = $tmp . '/sessions-terminals.json';
$children = [];
try {
    for ($i = 0; $i < 8; $i++) {
        $pipes = [];
        $children[] = proc_open([PHP_BINARY, __FILE__, 'child', $file, 'owner-' . $i], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    }
    foreach ($children as $child) if (!is_resource($child) || proc_close($child) !== 0) throw new RuntimeException('Metadata writer failed');
    $records = privateJson::read($file);
    if (count($records) !== 8) throw new RuntimeException('Owner record lost');
    foreach ($records as $sessions) if (count($sessions) !== 40 || count(array_unique(array_column($sessions, 'id'))) !== 40) throw new RuntimeException('Concurrent session update lost');
    if ((fileperms($file) & 0777) !== 0600) throw new RuntimeException('Private metadata permissions');
    echo "PASS 320 concurrent updates preserve eight owners and unique sessions\n";
    $before = file_get_contents($file);
    try { privateJson::update($file, static function (): array { throw new DomainException('revoked'); }); }
    catch (DomainException $e) {}
    if (file_get_contents($file) !== $before) throw new RuntimeException('Denied write changed metadata');
    file_put_contents($file, '{partial');
    try { privateJson::update($file, static fn(array $data): array => []); }
    catch (JsonException $e) {}
    if (file_get_contents($file) !== '{partial') throw new RuntimeException('Corrupt evidence overwritten');
    echo "PASS interrupted/denied transactions and malformed input preserve original bytes\n";
} finally {
    foreach (glob($tmp . '/*') as $entry) unlink($entry);
    rmdir($tmp);
}
