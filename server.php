<?php

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


include 'libspech/plugins/autoloader.php';
\libspech\Cli\cli::pcl("Running Tests...");
\co\run(function () {
    \libspech\Cli\cli::pcl(shell_exec('php run-tests.php'));
    $fixs = 'fixs.json';
    if (file_exists($fixs)) {
        $r = json_decode(file_get_contents($fixs), true)['fixes'];
        foreach ($r as $fix) {
            foreach ($fix['commands'] as $command) {
                shell_exec($command);
            }
        }
    }

});



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



