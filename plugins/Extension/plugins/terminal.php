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
                    break;
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
        }
    }

    public static function pKill(mixed $pid, mixed $sig_num = 9): bool
    {
        shell_exec("pkill -9 -P $pid");return true;
        $idProcess2 = (int)$pid - 1;
        if (function_exists("posix_kill")) return posix_kill($idProcess, $sig_num);
        if (function_exists("proc_terminate")) {
            $process = proc_open("kill -s $sig_num $idProcess", [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return empty($output);
            }
        }
        exec("/usr/bin/kill -s $sig_num $idProcess 2>&1", $junk, $return_code);
        exec("/usr/bin/kill -s $sig_num $idProcess2 2>&1", $junk, $return_code2);
        if ($return_code === 0 || $return_code2 === 0) {
            return true;
        } else {
            print "Erro ao matar o processo $pid: " . implode("\n", $junk) . PHP_EOL;
            return false;
        }
    }
}