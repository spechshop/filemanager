<?php
declare(strict_types=1);
// Snapshot of upstream's 2026-10-21 advisory, not evidence about the incident.
$minimum = ['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$patched = isset($minimum[$branch]) && version_compare(PHP_VERSION, $minimum[$branch], '>=');
$requireSwoole = in_array('--require-swoole', $argv, true);
$swoole = extension_loaded('swoole') ? phpversion('swoole') : null;
echo json_encode([
    'php' => PHP_VERSION,
    'swoole' => $swoole,
    'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    'advisoryDate' => '2026-10-21',
    'minimumPatchedPhp' => $minimum[$branch] ?? null,
    'patchedPhp' => $patched,
    'requiredSwoolePresent' => !$requireSwoole || $swoole !== null,
    'source' => 'https://www.php.net/archive/2026.php#2026-10-21-5',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($patched && (!$requireSwoole || $swoole !== null) ? 0 : 1);
