<?php

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
use plugins\Start\console as consoleDeclares;
use Swoole\Coroutine as co;
function portAlive(mixed $port): bool
{
    $host = "0.0.0.0";
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    var_dump($fp);
    if (!$fp) {
        return false;
    }
    fclose($fp);
    return true;
}
// antes checamos se o php do ambiente tem swoole, se não tiver usamos o ./php adicionando o em path local
$existSwoole = shell_exec('php --ri swoole');
if (!$existSwoole) {
    $existSwoole = shell_exec('./php --ri swoole');
}
if (!$existSwoole) {
    print "Swoole not found in path, please install it or add it to your path\n";
    exit(1);
} else {
    // setamos para quando usar o comando php nessa sessão, ele use o ./php
    putenv('PATH=' . getcwd() . ':$PATH');
    print shell_exec('php -m | grep swoole');
}



include 'libspech/plugins/autoloader.php';
\libspech\Cli\cli::pcl("Running Tests...");
\co\run(function () {
    global $argv;
    if (@$argv[1] !== '--fix')
    \libspech\Cli\cli::pcl(shell_exec('php run-tests.php'));
    $fixs = 'fixs.json';
    if (file_exists($fixs)) {
        $r = json_decode(file_get_contents($fixs), true)['fixes'];
        print "Running fixes...\n";
        foreach ($r as $fix) {
            foreach ($fix['commands'] as $command) {
              print  shell_exec($command);
            }
        }
    }

});


require_once __DIR__ . '/vendor/autoload.php';
include_once 'plugins/autoload.php';
for (; ;) {
    print "Starting server...\n";
    $sharedPid = null;
    $pidRunner = null;
    Co\run(function () use (&$sharedPid, &$pidRunner) {
        \plugins\terminal::asyncShell('php ' . __DIR__ . "/middleware.php", (new consoleDeclares()), $sharedPid);
    });

    Co\run(fn() => co::sleep(3));
    print "Restarting $sharedPid and $pidRunner...\n";
    \plugins\terminal::pKill($sharedPid);
}



