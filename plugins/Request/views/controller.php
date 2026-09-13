<?php

namespace plugins\Request;

class controller
{
    private static ?array $metadata = null;
    private static array $pages = [];
    private static int $sampledAt = 0;

    public static function listPages(): ?array
    {
        $pages = [];
        $filePath = explode('/Request', __DIR__)[0] . '/Request/pages/';
        clearstatcache(true, $filePath);
        $stat = @stat($filePath);
        $metadata = $stat === false ? null : [$stat['mtime'], $stat['ctime'], $stat['ino']];
        if ($metadata !== null && self::$metadata === $metadata
            && max($stat['mtime'], $stat['ctime']) < self::$sampledAt) {
            return self::$pages;
        }
        if ($handle = opendir($filePath)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $pages[] = $filePath . $entry;
                }
            }
            closedir($handle);
        }
        self::$metadata = $metadata;
        self::$sampledAt = time();
        return self::$pages = $pages;
    }
}
