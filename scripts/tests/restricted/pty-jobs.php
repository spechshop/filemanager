<?php
// Real proc_open PTY, without Node, network, or root.
$p = proc_open(['/usr/bin/setsid', '--ctty', '/bin/bash', '--noprofile', '--norc', '-i'], [['pty'], ['pty'], ['pty']], $pipes, null, ['PATH'=>'/usr/local/bin:/usr/bin:/bin', 'TERM'=>'xterm', 'PS1'=>'FM> ']);
if (!is_resource($p)) throw new RuntimeException('proc_open failed');
$pid = proc_get_status($p)['pid'];
stream_set_blocking($pipes[0], false);
function readUntil($pipe, string $expected): string {
    $out = ''; $end = microtime(true) + 5;
    do { $out .= fread($pipe, 65536); if (str_contains($out, $expected)) return $out; usleep(20000); } while (microtime(true) < $end);
    throw new RuntimeException("Missing $expected: $out");
}
try {
    $out = readUntil($pipes[0], 'FM>');
    if (str_contains($out, 'no job control')) throw new RuntimeException($out);
    fwrite($pipes[0], "ps -o pid,sid,pgid,tpgid,tty -p $$; ls / >/dev/null; cat /etc/os-release | grep NAME; echo COMMANDS_OK\n");
    $commands = readUntil($pipes[0], 'FM>');
    if (!str_contains($commands, 'NAME=') || !str_contains($commands, 'pts/')) throw new RuntimeException($commands);
    fwrite($pipes[0], "sleep 30\n"); usleep(200000); fwrite($pipes[0], "\x1a");
    readUntil($pipes[0], 'FM>');
    fwrite($pipes[0], "bg\n"); readUntil($pipes[0], 'FM>');
    fwrite($pipes[0], "fg\n"); usleep(200000); fwrite($pipes[0], "\x03"); readUntil($pipes[0], 'FM>');
    fwrite($pipes[0], "stty cols 101 rows 37; stty size\n"); readUntil($pipes[0], '37 101');
    fwrite($pipes[0], "exit\n");
    echo "PASS proc_open: controlling tty, commands, Ctrl+Z/bg/fg/Ctrl+C, resize\n";
} finally {
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
        $data = @file_get_contents($stat);
        if (!is_string($data)) continue;
        $fields = explode(' ', trim(substr($data, strrpos($data, ')') + 1)));
        if ((int) ($fields[3] ?? 0) === $pid) @posix_kill((int) basename(dirname($stat)), SIGKILL);
    }
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($p);
}
