<?php

namespace plugins\Database;

use plugins\Request\appController;

class call
{
    private static ?array $metadata = null;
    private static ?array $tokens = null;
    private static int $sampledAt = 0;

    public static function data(): ?array
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        clearstatcache(true, $addressTokens);
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        $stat = @stat($addressTokens);
        if ($stat === false) return null;
        $metadata = [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino']];
        if (self::$metadata === $metadata && max($stat['mtime'], $stat['ctime']) < self::$sampledAt) {
            return self::$tokens;
        }
        $contents = @file_get_contents($addressTokens);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;
        self::$tokens = is_array($decoded) ? $decoded : null;
        self::$metadata = $metadata;
        self::$sampledAt = time();
        return self::$tokens;
    }
}
