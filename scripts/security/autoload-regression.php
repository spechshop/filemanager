<?php
declare(strict_types=1);
namespace plugins\Database {
    class call { public static function data(): array { return []; } }
}
namespace plugins\Request {
    class controller { public static function listPages(): array { return []; } }
}
namespace {
    $root = dirname(__DIR__, 2);
    $fixtureDirectory = sys_get_temp_dir() . '/fm-loader-' . bin2hex(random_bytes(12));
    mkdir($fixtureDirectory, 0700);
    file_put_contents($fixtureDirectory . '/one.php', '<?php require_once __DIR__ . "/two.php"; class FixtureLoaderOne {}');
    file_put_contents($fixtureDirectory . '/two.php', '<?php require_once __DIR__ . "/one.php"; class FixtureLoaderTwo {}');
    file_put_contents($fixtureDirectory . '/untrusted.txt', '<?php throw new RuntimeException("Non-PHP plugin loaded");');
    symlink($fixtureDirectory . '/untrusted.txt', $fixtureDirectory . '/linked.php');
    $config = $fixtureDirectory . '/config.json';
    // Resolve a synthetic fixture directory relative to the real loader's base.
    $parents = count(array_filter(explode('/', $root . '/plugins')));
    $relative = str_repeat('../', $parents) . ltrim($fixtureDirectory, '/');
    file_put_contents($config, json_encode(['autoload' => [$relative], 'allowObservable' => []]));
    putenv('FILEMANAGER_CONFIG_FILE=' . $config);
    try {
        require $root . '/plugins/autoload.php';
        if (!class_exists('FixtureLoaderOne', false) || !class_exists('FixtureLoaderTwo', false)) throw new RuntimeException('Plugin dependencies missing');
        echo "PASS actual loader includes dependent classes once and skips symlinks/non-PHP files\n";
    } finally {
        foreach (scandir($fixtureDirectory) as $name) if ($name !== '.' && $name !== '..') unlink($fixtureDirectory . '/' . $name);
        rmdir($fixtureDirectory);
    }
}
