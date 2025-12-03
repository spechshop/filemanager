<?php

use plugins\Start\console;

include "plugins/autoload.php";

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


for (; ;) {
    print "Starting server...\n";
    $sharedPid = null;
    $pidRunner = null;
    Co\run(function () use (&$sharedPid, &$pidRunner) {
        \plugins\terminal::asyncShell('php ' . __DIR__ . "/middleware.php", (new console()), $sharedPid);
    });

    Co\run(fn() => co::sleep(3));
    print "Restarting $sharedPid and $pidRunner...\n";
    \plugins\terminal::pKill($sharedPid);
}



