<?php
// Exercise the production caches in a disposable tree, never the user's tokens.
$source = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/filemanager-cache-' . bin2hex(random_bytes(6));
mkdir($root . '/database', 0700, true);
mkdir($root . '/plugins/Request/views', 0700, true);
mkdir($root . '/plugins/Request/pages', 0700, true);
copy($source . '/plugins/Request/views/controller.php', $root . '/plugins/Request/views/controller.php');
eval('namespace plugins\\Request; class appController { public static function baseDir() { return $GLOBALS["root"]; } }');
require $source . '/plugins/Database/call/call.php';
require $root . '/plugins/Request/views/controller.php';
$checks = 0;
function checkCache(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException($name);
    $GLOBALS['checks']++;
    echo "PASS $name\n";
}
try {
    $tokens = $root . '/database/tokens.lotus';
    checkCache(plugins\Database\call::data() === [], 'missing token store created');
    file_put_contents($tokens, '{"aaa":1}');
    checkCache(plugins\Database\call::data() === ['aaa' => 1], 'new token visible');
    file_put_contents($tokens, '{"bbb":2}');
    checkCache(plugins\Database\call::data() === ['bbb' => 2], 'same-second same-size replacement revokes old token');
    sleep(2);
    checkCache(plugins\Database\call::data() === ['bbb' => 2], 'stable token snapshot');
    file_put_contents($tokens, '{');
    checkCache(plugins\Database\call::data() === null, 'malformed data fails closed');
    file_put_contents($tokens, '"scalar"');
    checkCache(plugins\Database\call::data() === null, 'scalar JSON fails closed');
    file_put_contents($tokens . '.tmp', '{"ccc":3}');
    rename($tokens . '.tmp', $tokens);
    checkCache(plugins\Database\call::data() === ['ccc' => 3], 'atomic save recovers');
    unlink($tokens);
    checkCache(plugins\Database\call::data() === [], 'deleted store revokes tokens');
    $pages = $root . '/plugins/Request/pages';
    checkCache(plugins\Request\controller::listPages() === [], 'empty routes');
    file_put_contents($pages . '/one.html', 'one');
    checkCache(plugins\Request\controller::listPages() === [$pages . '/one.html'], 'new route');
    rename($pages . '/one.html', $pages . '/two.html');
    checkCache(plugins\Request\controller::listPages() === [$pages . '/two.html'], 'same-second renamed route');
    sleep(2);
    checkCache(plugins\Request\controller::listPages() === [$pages . '/two.html'], 'stable route snapshot');
    unlink($pages . '/two.html');
    checkCache(plugins\Request\controller::listPages() === [], 'deleted route');
    echo "$checks assertions passed\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($root);
}
