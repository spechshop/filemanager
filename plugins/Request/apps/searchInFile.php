<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class searchInFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::verifyToken($request)) {
            return security::invalidToken($response);
        }

        $response->header("Content-Type", "application/json");

        $search = trim($request->post["search"] ?? "");
        $currentPath = $request->post["path"] ?? appController::baseDir();
        $limit = (int) ($request->post["limit"] ?? 1000);

        if (!$search) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "error" => "Search string cannot be empty",
                ])
            );
        }

        $path = is_file($currentPath) ? dirname($currentPath) : $currentPath;
        $path = rtrim(str_replace("//", "/", $path), "/");

        $foundFiles = self::searchString($path, $search, $limit);
        $results = [];

        foreach ($foundFiles as $filePath) {
            $sizeKB = round(filesize($filePath) / 1024, 2) . " KB";
            $results[] = $filePath . " " . $sizeKB;
        }

        return $response->end(
            json_encode([
                "success" => true,
                "information" => $results,
            ])
        );
    }

    public static function searchString(string $local, string $string, int $limit, array &$found = []): array
    {
        if (!file_exists($local) || count($found) >= $limit) {
            return $found;
        }

        if (is_file($local)) {
            if (self::fileContainsString($local, $string)) {
                $found[] = $local;
            }
            return $found;
        }

        if (!is_dir($local)) {
            return $found;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $local,
                    \FilesystemIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\UnexpectedValueException $e) {
            return $found;
        }

        $processed = 0;
        foreach ($iterator as $item) {
            if (count($found) >= $limit) {
                return $found;
            }

            if (!$item->isFile()) {
                continue;
            }

            if (self::fileContainsString($item->getPathname(), $string)) {
                $found[] = $item->getPathname();
            }

            $processed++;
            if ($processed % 50 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $found;
    }

    public static function fileContainsString(string $filePath, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $needleLength = strlen($needle);
        $chunkSize = self::getSearchChunkSize();
        $tail = '';

        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $tail . $chunk;
            if (stripos($buffer, $needle) !== false) {
                fclose($handle);
                return true;
            }

            if ($needleLength > 1) {
                $tail = substr($buffer, -($needleLength - 1));
            } else {
                $tail = '';
            }

            unset($chunk, $buffer);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        fclose($handle);
        return false;
    }

    public static function getSearchChunkSize(): int
    {
        $available = self::getAvailableMemoryBytes();
        if ($available <= 0) {
            return 64 * 1024;
        }

        $safeChunk = (int) floor($available / 16);
        $safeChunk = max(64 * 1024, $safeChunk);
        return min($safeChunk, 1024 * 1024);
    }

    private static function getAvailableMemoryBytes(): int
    {
        $limit = ini_get('memory_limit');
        $limitBytes = self::parseSizeToBytes($limit);

        if ($limitBytes === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        if ($limitBytes <= 0) {
            return 0;
        }

        $usage = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        return max(0, $limitBytes - $usage);
    }

    private static function parseSizeToBytes($value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return 0;
        }

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (!preg_match('/^(\d+)([KMGTP]?)$/i', $value, $matches)) {
            return (int) $value;
        }

        $number = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');
        $multiplier = match ($unit) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            'T' => 1024 * 1024 * 1024 * 1024,
            'P' => 1024 * 1024 * 1024 * 1024 * 1024,
            default => 1,
        };

        return $number * $multiplier;
    }
}
