<?php

namespace plugins\Request;

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

        if ($search === '') {
            return $response->end(
                json_encode([
                    "success" => false,
                    "error" => "Search string cannot be empty",
                ])
            );
        }

        $currentPath = securityPolicy::path($currentPath, securityPolicy::requestToken($request));
        $path = is_file($currentPath) ? dirname($currentPath) : $currentPath;

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
        if (count($found) >= $limit) return $found;
        foreach (self::walk($local, $owner, $seen) as $entry) {
            if (count($found) >= $limit) break;
            if (!$entry['directory'] && self::fileContainsString($entry['path'], $string, $owner)) $found[] = $entry['path'];
        }
        return $found;
    }

    /** Read-only traversal: skip inaccessible children, without weakening mutation preflights. */
    public static function walk(string $path, string $owner, array $seen = []): \Generator
    {
        $root = securityPolicy::path($path, $owner);
        $pending = [$root];
        while ($pending !== []) {
            $directory = array_pop($pending);
            try {
                $real = securityPolicy::path($directory, $owner);
                if (isset($seen[$real])) continue;
                $seen[$real] = true;
                // listing() filters broken and out-of-scope links using the descriptor broker.
                $entries = safeFiles::listing($real, $owner);
            } catch (\DomainException $e) {
                if ($directory === $root || !securityPolicy::validToken($owner)) throw $e;
                continue;
            }
            foreach ($entries as $entry) {
                yield $entry;
                if ($entry['directory']) $pending[] = $entry['path'];
            }
        }
    }

    public static function fileContainsString(string $filePath, string $needle, string $owner): bool
    {
        try {
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
        } catch (\DomainException $e) {
            // A file can disappear or become unreadable after its directory was listed.
            if (!securityPolicy::validToken($owner)) throw $e;
            return false;
        }
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
