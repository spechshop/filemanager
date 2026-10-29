<?php
declare(strict_types=1);
namespace plugins\Request;

/** Stable sidecar lock + atomic rename: every read/modify/write is one transaction. */
final class privateJson
{
    public static function lock($handle): bool
    {
        $deadline = microtime(true) + 10;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) return false;
            if (class_exists('Swoole\\Coroutine', false) && \Swoole\Coroutine::getCid() >= 0) \Swoole\Coroutine::sleep(0.005);
            else usleep(5000);
        }
        return true;
    }

    public static function read(string $path): array
    {
        if (!file_exists($path)) return [];
        if (is_link($path)) throw new \RuntimeException('Unsafe metadata file');
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid metadata');
        return $data;
    }

    public static function update(string $path, callable $mutator): array
    {
        if (is_link($path . '.lock')) throw new \RuntimeException('Unsafe metadata lock');
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !self::lock($lock)) throw new \RuntimeException('Metadata lock unavailable');
        $temporary = null;
        try {
            $updated = $mutator(self::read($path));
            if (!is_array($updated)) throw new \RuntimeException('Invalid metadata update');
            $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $temporary = tempnam(dirname($path), '.fm-json-');
            if ($temporary === false || file_put_contents($temporary, $json . "\n") === false) throw new \RuntimeException('Metadata write failed');
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new \RuntimeException('Metadata replacement failed');
            return $updated;
        } finally {
            if (is_string($temporary) && file_exists($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
