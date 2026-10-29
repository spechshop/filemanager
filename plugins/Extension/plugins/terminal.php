<?php
declare(strict_types=1);

namespace plugins;



class terminal
{
    public static function asyncShell($command, $cli, &$sharedPid = null): void
    {
        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start middleware');
        }
        $sharedPid = proc_get_status($process)['pid'];
        print $cli->color("Processo iniciado com sucesso\n", 'green');
        fclose($pipes[0]);
        unset($pipes[0]);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            while ($pipes !== []) {
                $ready = array_values($pipes);
                $write = $except = null;
                // Sleep in the OS until output or EOF; no 100 Hz status polling.
                if (@stream_select($ready, $write, $except, null) === false) {
                    // pcntl signals interrupt select(). Keep draining the child
                    // until EOF so graceful shutdown cannot lose its pipe IO.
                    continue;
                }
                foreach ($ready as $pipe) {
                    $data = fread($pipe, 65536);
                    if ($data !== false && $data !== '') {
                        print $cli->color($data, 'yellow');
                    }
                    if ($data === false || feof($pipe)) {
                        $key = array_search($pipe, $pipes, true);
                        fclose($pipe);
                        unset($pipes[$key]);
                    }
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            // Reap the child before returning; EOF is not a reason to kill an
            // already-reaped PID (which could have been reused by the OS).
            proc_close($process);
            $sharedPid = null;
        }
    }

    public static function pKill(mixed $pid, mixed $sig_num = 9): bool
    {
        if (!is_numeric($pid) || !is_numeric($sig_num)) return false;
        $processId = (int) $pid; $signal = (int) $sig_num;
        if ($processId <= 1 || $signal < 1 || $signal > 64) return false;
        if (function_exists('posix_kill')) return posix_kill($processId, $signal);
        $pipes = [];
        $process = proc_open(['/usr/bin/kill', '-s', (string) $signal, '--', (string) $processId],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        return is_resource($process) && proc_close($process) === 0;
    }
}
