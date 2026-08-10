<?php

namespace plugins\Extension;

class utilsFunction
{
    private const CPU_CACHE_TTL = 0.5;
    private const MEMORY_CACHE_TTL = 1.0;
    private const DISK_CACHE_TTL = 10.0;

    private static ?string $processorName = null;
    private static ?array $cpuSnapshot = null;
    private static float $cpuUsage = 0.0;
    private static float $cpuSampledAt = 0.0;
    private static ?array $memoryCache = null;
    private static float $memorySampledAt = 0.0;
    private static array $diskCache = [];

    public static function getProcessorName(): ?array
    {
        if (self::$processorName === null) {
            self::$processorName = "Unknown processor";
            $cpuInfo = @fopen("/proc/cpuinfo", "r");

            if ($cpuInfo !== false) {
                while (($line = fgets($cpuInfo)) !== false) {
                    if (str_starts_with($line, "model name")) {
                        self::$processorName = trim(substr($line, strpos($line, ":") + 1));
                        break;
                    }
                }
                fclose($cpuInfo);
            }
        }

        $usage = (float) self::getCpuUsage();

        return [
            "usage" => $usage,
            "name" => self::$processorName,
            "background" => self::usageBackground($usage)
        ];
    }

    public static function getCpuUsage(): ?string
    {
        $now = microtime(true);
        if (self::$cpuSnapshot !== null && ($now - self::$cpuSampledAt) < self::CPU_CACHE_TTL) {
            return number_format(self::$cpuUsage, 2, ".", "");
        }

        $current = self::readCpuStat();
        if ($current === null) {
            return number_format(self::$cpuUsage, 2, ".", "");
        }

        if (self::$cpuSnapshot !== null) {
            $totalDelta = $current["total"] - self::$cpuSnapshot["total"];
            $idleDelta = $current["idle"] - self::$cpuSnapshot["idle"];

            if ($totalDelta > 0) {
                self::$cpuUsage = max(0.0, min(100.0, (($totalDelta - $idleDelta) / $totalDelta) * 100));
            }
        }

        self::$cpuSnapshot = $current;
        self::$cpuSampledAt = $now;

        return number_format(self::$cpuUsage, 2, ".", "");
    }

    private static function readCpuStat(): ?array
    {
        $stat = @fopen("/proc/stat", "r");
        if ($stat === false) {
            return null;
        }

        $line = fgets($stat);
        fclose($stat);
        if ($line === false || !str_starts_with($line, "cpu ")) {
            return null;
        }

        $parts = preg_split("/\\s+/", trim($line));
        array_shift($parts);
        $times = array_map("intval", $parts);
        if (count($times) < 4) {
            return null;
        }

        return [
            "total" => array_sum($times),
            "idle" => $times[3] + ($times[4] ?? 0)
        ];
    }

    public static function getMemoryUsage(): ?array
    {
        $now = microtime(true);
        if (self::$memoryCache !== null && ($now - self::$memorySampledAt) < self::MEMORY_CACHE_TTL) {
            return self::$memoryCache;
        }

        $memInfo = @fopen("/proc/meminfo", "r");
        if ($memInfo === false) {
            return self::$memoryCache;
        }

        $values = [];
        while (($line = fgets($memInfo)) !== false) {
            if (preg_match('/^(MemTotal|MemFree|MemAvailable):\\s+(\\d+)/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
                if (count($values) === 3) {
                    break;
                }
            }
        }
        fclose($memInfo);

        $totalMem = $values["MemTotal"] ?? 0;
        $freeMem = $values["MemFree"] ?? 0;
        $availableMem = $values["MemAvailable"] ?? $freeMem;
        if ($totalMem <= 0) {
            return self::$memoryCache;
        }

        // /proc/meminfo usa kB. Mantemos MB/GB no contrato público existente.
        $totalMem /= 1024;
        $freeMem /= 1024;
        $usedMem = $totalMem - ($availableMem / 1024);
        $usedPercentage = ($usedMem / $totalMem) * 100;
        $unit = $totalMem >= 1024 ? "GB" : "MB";
        $divider = $unit === "GB" ? 1024 : 1;
        $usage = round($usedPercentage, 2);

        self::$memoryCache = [
            "total_mem" => round($totalMem / $divider, 2),
            "used_mem" => round($usedMem / $divider, 2),
            "free_mem" => round($freeMem / $divider, 2),
            "used_percentage" => $usage,
            "unit" => $unit,
            "background" => self::usageBackground($usage)
        ];
        self::$memorySampledAt = $now;

        return self::$memoryCache;
    }

    public static function getDiskUsage($path = "/"): ?array
    {
        $path = (string) $path;
        $now = microtime(true);
        if (isset(self::$diskCache[$path]) && ($now - self::$diskCache[$path]["sampled_at"]) < self::DISK_CACHE_TTL) {
            return self::$diskCache[$path]["value"];
        }

        $totalSpace = @disk_total_space($path);
        $freeSpace = @disk_free_space($path);
        if ($totalSpace === false || $freeSpace === false || $totalSpace <= 0) {
            return self::$diskCache[$path]["value"] ?? null;
        }

        $usedSpace = $totalSpace - $freeSpace;
        $usedPercentage = $usedSpace / $totalSpace * 100;
        $unit = $totalSpace >= 1024 ** 3 ? "GB" : "MB";
        $divider = $unit === "GB" ? 1024 ** 3 : 1024 ** 2;
        $totalSpaceFormatted = round($totalSpace / $divider, 2);
        $usedSpaceFormatted = round($usedSpace / $divider, 2);
        $freeSpaceFormatted = round($freeSpace / $divider, 2);
        $usedPercentage = round($usedPercentage, 2);
        $value = [
            "total_space" => $totalSpaceFormatted,
            "used_space" => $usedSpaceFormatted,
            "free_space" => $freeSpaceFormatted,
            "used_percentage" => $usedPercentage,
            "unit" => $unit,
            "background" => self::usageBackground($usedPercentage)
        ];
        self::$diskCache[$path] = ["sampled_at" => $now, "value" => $value];

        return $value;
    }

    private static function usageBackground(float $usage): string
    {
        if ($usage <= 25) {
            return "bg-success";
        }
        if ($usage <= 50) {
            return "bg-info";
        }
        if ($usage <= 75) {
            return "bg-warning";
        }

        return "bg-danger";
    }
    public static function toggleServer(string $idScreen, string $code): ?array
    {
        if (strpos($code, '"') !== false) {
            return [
                "success" => false,
                "message" => "Not allowed double quotes"
            ];
        }
        if (empty($idScreen) || empty($code)) {
            return [
                "success" => false,
                "message" => "Missing parameters"
            ];
        }
        exec("screen -ls", $outputCommand);
        $outputCommand = implode(" ", $outputCommand);
        if (strpos($outputCommand, $idScreen) !== false) {
            exec("screen -ls", $outputCommand);
            $outputCommand = implode(" ", $outputCommand);
            $splitLines = explode(" ", $outputCommand);
            foreach ($splitLines as $s) {
                $s = trim($s);
                if (strpos($s, $idScreen) !== false) {
                    $realWorker = explode("__", $s)[0];
                    exec("screen -XS {$realWorker}__ quit");
                }
            }
            $mode = "restart";
        } else {
            $mode = "start";
        }
        exec(sprintf("screen -dmS \"%s\" bash -c \"%s\"", $idScreen, $code));
        return [
            "success" => true,
            "message" => $mode
        ];
    }
    public static function simplePost(string $url, string $data): ?string
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        $resp = curl_exec($curl);
        curl_close($curl);
        return $resp;
    }
    public static function formatBytes($folderSize) {
        $units = [
            "B",
            "KB",
            "MB",
            "GB",
            "TB"
        ];
        $bytes = max($folderSize, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= 1024 ** $pow;
        return round($bytes, 2) . " " . $units[$pow];
    }
    public static function getFilePermissions($file): ?string
    {
        if (!file_exists($file)) {
            return false;
        }
        $permissions = fileperms($file);
        return substr(sprintf("%o", $permissions), -4);
    }
    public static function folderSize($file) {
        // usar o comando 'sl -sm' para listar o tamanho de todos os arquivos
        $size = 0;
        $command = "du -sb " . escapeshellarg($file);
        var_dump($command);
        $result = str_replace([
            "\t",
            "\r",
            "\n"
        ], " ", trim(shell_exec($command)));
        $size = explode(" ", $result)[0];
        $size = (int) $size;
        return $size;
    }
    public static function countItensInPath($path) {
        if (!isset($GLOBALS['count_cache'])) {
            $GLOBALS['count_cache'] = [];
        }

        $now = time();
        $cacheKey = md5($path);

        // Invalida cache se o diretório foi modificado ou cache expirou (2 min)
        $dirMtime = @filemtime($path);
        if (isset($GLOBALS['count_cache'][$cacheKey]) &&
            ($now - $GLOBALS['count_cache'][$cacheKey]['time']) < 120 &&
            $GLOBALS['count_cache'][$cacheKey]['mtime'] === $dirMtime) {
            return $GLOBALS['count_cache'][$cacheKey]['count'];
        }

        $fileCount = 0;
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            } else {
                $fileCount++;
            }
        }

        $GLOBALS['count_cache'][$cacheKey] = [
            'count' => $fileCount,
            'time' => $now,
            'mtime' => $dirMtime
        ];

        return $fileCount;
    }
    public static function isCompressedFile($filename) {
        $compressedExtensions = [
            "7z",
            "rar",
            "zip",
            "tar",
            "gz",
            "bz2",
            "xz"
        ];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $compressedExtensions);
    }
    public static function listCompressedFileContents($compressedFilePath) {
        $fileExtension = strtolower(pathinfo($compressedFilePath, PATHINFO_EXTENSION));
        $commands = [
            "zip" => "unzip -l",
            "rar" => "unrar l",
            "7z" => "7z l",
            "gz" => "tar -ztvf",
            "bz2" => "tar -jtvf",
            "xz" => "tar -Jtvf",
            "tar" => "tar -tvf"
        ];
        if (!isset($commands[$fileExtension])) {
            return [
                "success" => false,
                "message" => "Formato de arquivo não suportado."
            ];
        }
        $command = $commands[$fileExtension] . " " . escapeshellarg($compressedFilePath);
        exec($command, $output, $returnVar);
        if ($fileExtension === "7z") {
            $newOutput = $output;
            foreach ($newOutput as $key => $item) {
                $namePositon = strpos($item, "Name");
                if ($namePositon !== false) {
                    break;
                }
                unset($newOutput[$key]);
            }
            $listFiles = [];
            foreach ($newOutput as $key => $item) {
                if (strpos($item, "----") !== false) {
                    unset($newOutput[$key]);
                }
            }
            foreach ($newOutput as $key => $item) {
                if (strpos($item, "D....") !== false) {
                    unset($newOutput[$key]);
                }
            }
            foreach ($newOutput as $key => $item) {
                if (strpos($item, ",") !== false) {
                    unset($newOutput[$key]);
                }
            }
            foreach ($newOutput as $key => $item) {
                if (strpos($item, "...A") !== false) {
                    $listFiles[] = substr($item, 53);
                }
            }
            return $listFiles;
        }
        if ($returnVar !== 0) {
            return [
                "success" => false,
                "message" => "Erro ao listar o conteúdo do arquivo."
            ];
        }
        foreach ($output as $kk => $vv) {
            if (strpos($vv, "...D...") !== false) {
                unset($output[$kk]);
            }
        }
        $listFiles = [];
        foreach ($output as $key => $value) {
            $listFiles[] = @trim(preg_split("/:([0-9]{2})/", $value)[1]);
        }
        foreach ($listFiles as $k => $v) {
            if (strlen($v) < 1) {
                unset($listFiles[$k]);
            }
        }
        return array_chunk(array_values($listFiles), 500)[0];
    }
    public static function extractCompressedFile($filePath, $destination) {
        if (!file_exists($filePath)) {
            return [
                "success" => false,
                "message" => "O arquivo não existe."
            ];
        }
        $safeFilePath = escapeshellarg($filePath);
        $safeDestination = escapeshellarg($destination);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        switch ($extension) {
            case "zip":
                $command = "unzip -o {$safeFilePath} -d {$safeDestination}";
                break;
            case "tar":
                $command = "tar -xf {$safeFilePath} -C {$safeDestination}";
                break;
            case "gz":
                $command = "tar -xzf {$safeFilePath} -C {$safeDestination}";
                break;
            case "bz2":
                $command = "tar -xjf {$safeFilePath} -C {$safeDestination}";
                break;
            case "rar":
                $command = "unrar x -o+ {$safeFilePath} {$safeDestination}";
                break;
            case "xz":
                $command = "tar -xJf {$safeFilePath} -C {$safeDestination}";
                break;
            case "7z":
                $command = "7z x {$safeFilePath} -o {$safeDestination}";
                break;
            default:
                return [
                    "success" => false,
                    "message" => "Formato de arquivo não suportado."
                ];
        }
        $output = shell_exec($command);
        if (strpos($output, "error") !== false) {
            return [
                "success" => false,
                "message" => "Erro ao extrair o arquivo."
            ];
        }
        return [
            "success" => true,
            "message" => "Arquivo extraído com sucesso."
        ];
    }
    public static function renameItem($currentName, $newName): ?bool
    {
        if (!file_exists($currentName)) {
            return false;
        }
        if (!rename($currentName, $newName)) {
            return false;
        }
        return true;
    }
    public static function listFiles($dir) {
        $result = [];
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }
            $filePath = $dir . "/" . $file;
            if (is_dir($filePath)) {
                $result = array_merge($result, self::listFiles($filePath));
            } else {
                $result[] = $filePath;
            }
        }
        return $result;
    }
    public static function createZipWithFolders(array $files, string $destination): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        // Deduz o diretório base a partir do destino do zip
        $base = dirname($destination);
        $base = rtrim(realpath($base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $realPath = realpath($file);
            if (is_dir($realPath)) {
                // Adiciona diretório recursivamente
                self::addDirectoryToZip($zip, $realPath, $base);
            } else {
                // Adiciona arquivo individual usando streaming
                $relativePath = ltrim(str_replace($base, '', $realPath), '/\\');
                self::addFileToZipStreaming($zip, $realPath, $relativePath);
            }
        }
        return $zip->close();
    }
    private static function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $base): void
    {
        $dirPath = rtrim($dirPath, '/\\') . DIRECTORY_SEPARATOR;
        $relativePath = ltrim(str_replace($base, '', $dirPath), '/\\');
        // Adiciona o diretório vazio
        $zip->addEmptyDir($relativePath);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $itemPath = $item->getRealPath();
            $relativePath = ltrim(str_replace($base, '', $itemPath), '/\\');
            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                self::addFileToZipStreaming($zip, $itemPath, $relativePath);
            }
        }
    }
    private static function addFileToZipStreaming(\ZipArchive $zip, string $filePath, string $zipPath): bool
    {
        // addFile do ZipArchive já é otimizado e não carrega o arquivo todo na RAM
        // Ele usa mmap internamente para arquivos grandes
        return $zip->addFile($filePath, $zipPath);
    }
    public static function isImage($filePath) {
        if (!file_exists($filePath)) {
            return false;
        }
        $size = @getimagesize($filePath);
        return is_array($size);
    }
    public static function isMediaFile($filePath) {
        $midias = [
            'png',
            'mp4',
            'mkv',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm',
            'mpeg',
            'mpg',
            '3gp',
            'm4v',
            'ts',
            'vob',
            'ogv',
            'm2ts',
            'mts',
            'rmvb',
            'asf',
            'divx',
            'xvid'
        ];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($extension, $midias)) {
            return true;
        }
        return false;
    }
    public static function isMovie(mixed $n) {
        $video = [];
        $value = explode(".", $n)[0];
        $audio = [
            'wav',
            'mp3',
            'ogg',
            'flac',
            'aac',
            'm4a',
            'wma',
            'opus',
            'aiff',
            'alac',
            'ape',
            'mp2'
        ];
        if (in_array($value, $audio)) {
            return true;
        }
        return false;
    }
    public static function openPort(mixed $port): bool
    {
        if (!is_numeric($port)) {
            return false;
        }
        $port = (int) $port;
        $fp = @fsockopen("127.0.0.1", $port, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return false;
        }
        return true;
    }
}