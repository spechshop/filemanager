<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class searchInFile
{
    public static function api(Request $request, Response $response)
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;

        $response->header("Content-Type", "application/json");

        $search = trim($request->post["search"] ?? "");
        $currentPath = $request->post["path"] ?? securityPolicy::initialPath(securityPolicy::requestToken($request));
        $limit = (int) ($request->post["limit"] ?? 1000);

        if (!$search) {
            return $response->end(
                json_encode([
                    "success" => false,
                    "error" => "Search string cannot be empty",
                ])
            );
        }

        securityPolicy::tree($currentPath, securityPolicy::requestToken($request));
        $path = is_file($currentPath) ? dirname($currentPath) : $currentPath;
        $path = rtrim(str_replace("//", "/", $path), "/");

        $foundFiles = self::searchString($path, $search, max(1, min(10000, $limit)), securityPolicy::requestToken($request));
        $results = [];

        foreach ($foundFiles as $filePath) {
            $sizeKB = "";
            $results[] = $filePath . " " . $sizeKB;
        }

        return $response->end(
            json_encode([
                "success" => true,
                "information" => $results,
            ])
        );
    }

    public static function searchString(string $local, string $string, int $limit, string $owner, array &$found = [], array $seen = []): array
    {
        $real = securityPolicy::path($local, $owner);
        if (isset($seen[$real]) || count($found) >= $limit) return $found;
        $seen[$real] = true;
        foreach (safeFiles::listing($real, $owner) as $entry) {
            if (count($found) >= $limit) break;
            if ($entry['directory']) self::searchString($entry['path'], $string, $limit, $owner, $found, $seen);
            elseif (self::fileContainsString($entry['path'], $string, $owner)) $found[] = $entry['path'];
        }
        return $found;
    }

    public static function fileContainsString(string $filePath, string $needle, string $owner): bool
    {
        $operation = safeFiles::reader($filePath, $owner);
        $tail = ''; $found = false;
        try {
            while (!feof($operation['stream'])) {
                $chunk = fread($operation['stream'], self::getSearchChunkSize());
                if ($chunk === false || $chunk === '') break;
                if (stripos($tail . $chunk, $needle) !== false) { $found = true; break; }
                $tail = strlen($needle) > 1 ? substr($tail . $chunk, -(strlen($needle) - 1)) : '';
            }
        } finally { safeFiles::close($operation, $found); }
        return $found;
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
