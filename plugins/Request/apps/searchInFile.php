<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine;

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
            $content = @file_get_contents($local);
            if ($content !== false && stripos($content, $string) !== false) {
                $found[] = $local;
            }
            //print "Searching in file: $local\n"; // Debugging line
            return $found;
        }

        if (!is_dir($local)) {
            return $found;
        }

        $dir = @opendir($local);
        if ($dir === false) {
            return $found;
        }

        $tasks = [];
        $running = 0;
        $maxConcurrent = 10;

        while (($file = readdir($dir)) !== false && count($found) < $limit) {
            if (in_array($file, [".", "..", ".htaccess"])) {
                continue;
            }

            $realPath = rtrim($local, "/") . "/" . $file;

            // Quando atingir o máximo de corotinas, espera
            while ($running >= $maxConcurrent) {
                Coroutine::sleep(0.001); // ou yield para evitar busy loop
            }

            $running++;
            Coroutine::create(function () use ($realPath, $string, $limit, &$found, &$running) {
                self::searchString($realPath, $string, $limit, $found);
                $running--;
            });
        }

        closedir($dir);

        // Aguarda todas terminarem
        while ($running > 0) {
            Coroutine::sleep(0.001);
        }

        return $found;
    }
}
