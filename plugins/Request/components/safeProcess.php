<?php
declare(strict_types=1);
namespace plugins\Request;

/** Finite tools only. Interactive terminals have their own session lifecycle. */
final class safeProcess
{
    private static array $active = [];
    private static int $total = 0;

    /** @return array{exit: int, output: string} */
    public static function run(array $arguments, string $token, bool $admin = false, ?string $directory = null, ?array $environment = null): array
    {
        self::authorize($token, $admin);
        if ((self::$active[$token] ?? 0) >= securityPolicy::limit('AUXILIARY_PER_USER', 2)
            || self::$total >= securityPolicy::limit('AUXILIARY_TOTAL', 16)) throw new \DomainException('Auxiliary process quota exceeded');
        $request = json_encode(['argv' => $arguments, 'token' => $token, 'admin' => $admin,
            'tokenFile' => securityPolicy::tokenFile(), 'cwd' => $directory, 'env' => $environment,
            'seconds' => securityPolicy::limit('AUXILIARY_TIMEOUT_SECONDS', 300),
            'bytes' => securityPolicy::limit('AUXILIARY_OUTPUT_BYTES', 16777216)], JSON_THROW_ON_ERROR);
        self::$active[$token] = (self::$active[$token] ?? 0) + 1;
        self::$total++;
        $process = null; $pipes = [];
        try {
            $process = \proc_open([getenv('FILEMANAGER_PYTHON') ?: '/usr/bin/python3', '-I',
                appController::baseDir() . 'scripts/security/process-broker.py'],
                [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, null,
                ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/nonexistent', 'LANG' => 'C.UTF-8']);
            if (!is_resource($process)) throw new \RuntimeException('Auxiliary supervisor unavailable');
            fwrite($pipes[0], $request . "\n"); fclose($pipes[0]);
            $result = json_decode((string) stream_get_contents($pipes[1]), true);
            fclose($pipes[1]);
            $status = proc_close($process); $process = null;
            if ($status !== 0 || !is_array($result) || empty($result['ok'])) throw new \DomainException('Auxiliary process denied or interrupted');
            self::authorize($token, $admin);
            $output = base64_decode($result['output'], true);
            if ($output === false) throw new \RuntimeException('Invalid auxiliary output');
            return ['exit' => (int) $result['exit'], 'output' => $output];
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            self::$active[$token]--; self::$total--;
            if (self::$active[$token] === 0) unset(self::$active[$token]);
        }
    }

    private static function authorize(string $token, bool $admin): void
    {
        if ($admin) securityPolicy::requireAdmin($token);
        elseif (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
    }
}
