<?php
declare(strict_types=1);
foreach (['appController', 'securityPolicy', 'safeFiles'] as $component) require dirname(__DIR__, 2) . '/plugins/Request/components/' . $component . '.php';
use plugins\Request\safeFiles;
$tmp = sys_get_temp_dir() . '/fm-git-publication-' . bin2hex(random_bytes(10));
mkdir($tmp, 0700); mkdir($tmp . '/projects'); mkdir($tmp . '/independent'); mkdir($tmp . '/private'); mkdir($tmp . '/outside');
putenv('FILEMANAGER_TOKENS_FILE=' . $tmp . '/tokens.json');
file_put_contents($tmp . '/tokens.json', json_encode(['git-fixture' => ['expire' => time() + 60, 'allowedRoots' => [$tmp . '/projects', $tmp . '/independent']]]));
try {
    mkdir($tmp . '/private/safe'); file_put_contents($tmp . '/private/safe/file.txt', 'repository fixture');
    file_put_contents($tmp . '/independent/reference', 'authorized link target');
    symlink('file.txt', $tmp . '/private/safe/local-link'); symlink($tmp . '/independent/reference', $tmp . '/private/safe/independent-link');
    safeFiles::publishGit($tmp . '/private/safe', $tmp . '/projects/imported', 'git-fixture');
    if (file_get_contents($tmp . '/projects/imported/file.txt') !== 'repository fixture'
        || !is_link($tmp . '/projects/imported/local-link') || file_get_contents($tmp . '/projects/imported/independent-link') !== 'authorized link target') throw new RuntimeException('Legitimate Git publication failed');
    mkdir($tmp . '/private/denied'); file_put_contents($tmp . '/private/denied/first-safe', 'fixture');
    symlink($tmp . '/outside', $tmp . '/private/denied/late-link');
    $denied = false;
    try { safeFiles::publishGit($tmp . '/private/denied', $tmp . '/projects/denied', 'git-fixture'); } catch (DomainException $error) { $denied = true; }
    if (!$denied || file_exists($tmp . '/projects/denied') || is_link($tmp . '/projects/denied')) throw new RuntimeException('Denied Git import produced effects');
    echo "PASS Git publication preserves legitimate links and rejects escaping links before any destination effect\n";
} finally {
    $clean = function ($path) use (&$clean) { if (is_link($path) || !is_dir($path)) { unlink($path); return; } foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') $clean($path . '/' . $name); rmdir($path); }; $clean($tmp);
}
