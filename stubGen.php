<?php

Co\run(function () {
    function executeCommand($command): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        $pid = proc_get_status($process)['pid'];
        if (is_resource($process)) {
            fclose($pipes[0]);
            $outputPipes = [$pipes[1], $pipes[2]];
            while (count($outputPipes) > 0) {
                $readyPipes = $outputPipes;
                $null = null;
                if (stream_select($readyPipes, $null, $null, null) === false) {
                    break;
                }
                foreach ($readyPipes as $pipe) {
                    $data = fgets($pipe);
                    if ($data === false) {
                        fclose($pipe);
                        $outputPipes = array_diff($outputPipes, [$pipe]);
                    } else {
                        if (str_contains($data, 'FATAL ERROR')) {
                            executeCommand('clear');
                            echo $data;
                            $message = sprintf("server restarted at %s%s", date('d/m/Y H:i:s'), PHP_EOL);
                            $messageGreen = sprintf("O servidor foi reiniciado devido a uma modifição de um arquivo!%s%s", PHP_EOL, PHP_EOL);
                            print($message);
                            print($messageGreen);
                            proc_close($process);
                            if (is_resource($pipes[0])) fclose($pipes[0]);
                            if (is_resource($pipes[1])) fclose($pipes[1]);
                            if (is_resource($pipes[2])) fclose($pipes[2]);
                            pKill($pid, 9);
                            return;
                        } else {
                            echo $data;
                        }
                    }
                }
            }
            proc_close($process);
        }
    }
    function pKill(mixed $pid, mixed $sig_num = 9): bool
    {
        $idProcess = (int)$pid;
        if (function_exists("posix_kill")) return posix_kill($idProcess, $sig_num);
        exec("/usr/bin/kill -s $sig_num $idProcess 2>&1", $junk, $return_code);
        return !$return_code;
    }


    executeCommand('php novo.php');
    //executeCommand('php zz.php files');
    //executeCommand('php zz.php files yes');
});
