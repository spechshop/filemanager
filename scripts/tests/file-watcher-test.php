<?php
require dirname(__DIR__, 2) . '/plugins/Start/server/fileWatcher.php';
use plugins\Start\fileWatcher;
$root = sys_get_temp_dir() . '/filemanager-watcher-' . getmypid();
mkdir($root . '/plugins', 0700, true);
$tests = 0;
function check(bool $value, string $label): void {
    global $tests;
    if (!$value) throw new RuntimeException($label);
    $tests++;
    echo "PASS $label\n";
}
function removeTree(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') removeTree($path . '/' . $name);
    rmdir($path);
}
try {
    $file = $root . '/plugins/main.php';
    file_put_contents($file, '<?php /* first */');
    $watcher = new fileWatcher([$root . '/plugins'], ['php']);
    check($watcher->changes() === [], 'initial snapshot');
    check($watcher->changes() === [], 'unchanged');
    file_put_contents($file, '<?php /* other */');
    check($watcher->changes() === [$file], 'same-size save in same second');
    check($watcher->changes() === [], 'one notification per content change');
    touch($file, time() + 2);
    check($watcher->changes() === [], 'touch without content change');
    $created = $root . '/plugins/new.php';
    file_put_contents($created, '<?php');
    check($watcher->changes() === [$created], 'create');
    rename($created, $created . '.php');
    $changes = $watcher->changes();
    check(count($changes) === 2 && in_array($created, $changes) && in_array($created . '.php', $changes), 'rename');
    unlink($created . '.php');
    check($watcher->changes() === [$created . '.php'], 'delete');
    foreach (['vendor', 'node_modules', 'files', 'stubs', 'terminals'] as $ignored) {
        mkdir($root . '/plugins/' . $ignored);
        file_put_contents($root . '/plugins/' . $ignored . '/ignored.php', '<?php');
        check($watcher->changes() === [], "ignored $ignored");
    }
    mkdir($root . '/outside');
    file_put_contents($root . '/outside/external.php', '<?php');
    symlink($root . '/outside', $root . '/plugins/external');
    check($watcher->changes() === [], 'does not recurse through symlinks');
    mkdir($root . '/plugins/nested');
    file_put_contents($root . '/plugins/nested/a.php', '<?php');
    check(count($watcher->changes()) === 1, 'new directory');
    chmod($root . '/plugins/nested', 0000);
    check($watcher->changes() === [], 'unreadable directory does not cause restart loop');
    chmod($root . '/plugins/nested', 0700);
    check($watcher->changes() === [], 'permission recovery');
    unlink($root . '/plugins/nested/a.php'); rmdir($root . '/plugins/nested');
    check(count($watcher->changes()) === 1, 'deleted directory');
    $empty = new fileWatcher(['/nonexistent'], []);
    check($empty->changes() === [], 'empty extension list');
    file_put_contents($root . '/plugins/unobserved.txt', 'ignored');
    check($watcher->changes() === [], 'unobserved extension');
    for ($i = 0; $i < 20; $i++) file_put_contents($file, '<?php // save ' . $i);
    check($watcher->changes() === [$file], 'burst of saves coalesced');
    check($watcher->changes() === [], 'burst does not repeat');
    echo "$tests assertions passed\n";
} finally {
    removeTree($root);
}
