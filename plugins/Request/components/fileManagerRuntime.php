<?php

namespace plugins\Request;

class fileManagerRuntime
{
    private const RESTART_REQUEST_TTL = 120;

    public static function requestRestart(): void
    {
        $marker = self::restartMarker();
        $temporary = tempnam(sys_get_temp_dir(), '.lotus-filemanager-restart.');
        $payload = json_encode([
            'requestedAt' => time(),
            'middlewarePid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES);

        if ($temporary === false
            || $payload === false
            || @file_put_contents($temporary, $payload . PHP_EOL, LOCK_EX) === false
            || !@rename($temporary, $marker)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Não foi possível registrar a solicitação de reinício.');
        }
    }

    public static function consumeRestartRequest(): bool
    {
        $marker = self::restartMarker();
        if (!is_file($marker)) {
            return false;
        }

        $contents = @file_get_contents($marker);
        $request = is_string($contents) ? json_decode($contents, true) : null;
        @unlink($marker);

        $requestedAt = is_array($request) ? (int) ($request['requestedAt'] ?? 0) : 0;
        return $requestedAt > 0 && (time() - $requestedAt) <= self::RESTART_REQUEST_TTL;
    }

    public static function middlewareProcessIds(): array
    {
        $script = realpath(dirname(__DIR__, 3) . '/middleware.php');
        if ($script === false) {
            return [];
        }

        $processIds = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
            $processId = (int) basename(dirname($cmdlineFile));
            if ($processId <= 1) {
                continue;
            }

            $commandLine = @file_get_contents($cmdlineFile);
            if (!is_string($commandLine) || $commandLine === '') {
                continue;
            }
            $arguments = array_values(array_filter(
                explode("\0", $commandLine),
                static fn(string $argument): bool => $argument !== ''
            ));
            $workingDirectory = (string) @readlink("/proc/$processId/cwd");
            foreach (array_slice($arguments, 1) as $argument) {
                $candidate = str_starts_with($argument, '/')
                    ? $argument
                    : $workingDirectory . '/' . $argument;
                if (realpath($candidate) === $script) {
                    $processIds[] = $processId;
                    break;
                }
            }
        }

        rsort($processIds, SORT_NUMERIC);
        return array_values(array_unique($processIds));
    }

    private static function restartMarker(): string
    {
        $userId = function_exists('posix_geteuid') ? (string) posix_geteuid() : (string) getmyuid();
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'lotus-filemanager-restart-'
            . hash('sha256', $userId . ':' . dirname(__DIR__, 3)) . '.json';
    }
}
