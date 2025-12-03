<?php

namespace plugins\Extension;

class utilsFunction
{
    public static function getProcessorName(): ?array
    {
        $cpuinfo = file_get_contents("/proc/cpuinfo");
        $lines = explode("\n", $cpuinfo);
        $processorName = "";
        foreach ($lines as $line) {
            if (strpos($line, "model name") !== false) {
                $parts = explode(":", $line);
                $processorName = trim($parts[1]);
                break;
            }
        }

        $usage = round(self::getCpuUsage(), 2);
        if ($usage >= 0 && $usage <= 25) {
            $backgroundColor = "bg-success";
        } elseif ($usage > 25 && $usage <= 50) {
            $backgroundColor = "bg-info";
        } elseif ($usage > 50 && $usage <= 75) {
            $backgroundColor = "bg-warning";
        } elseif ($usage > 75 && $usage <= 100) {
            $backgroundColor = "bg-danger";
        }
        return [
            "usage" => $usage,
            "name" => $processorName,
            "background" => $backgroundColor,
        ];
    }

    public static function getCpuUsage(): ?string
    {
        $cont = file("/proc/stat");
        $cpuloadtmp = explode(" ", $cont[0]);
        $cpuload0[0] = $cpuloadtmp[2] + $cpuloadtmp[4];
        $cpuload0[1] = $cpuloadtmp[2] + $cpuloadtmp[4] + $cpuloadtmp[5];
        sleep(1);
        $cont = file("/proc/stat");
        $cpuloadtmp = explode(" ", $cont[0]);
        $cpuload1[0] = $cpuloadtmp[2] + $cpuloadtmp[4];
        $cpuload1[1] = $cpuloadtmp[2] + $cpuloadtmp[4] + $cpuloadtmp[5];
        return (($cpuload1[0] - $cpuload0[0]) * 100) / ($cpuload1[1] - $cpuload0[1]);
    }

    private static function readCpuStat(): array
    {
        $line = file_get_contents("/proc/stat");
        $parts = preg_split("/\s+/", trim(explode("\n", $line)[0]));
        array_shift($parts); // remove 'cpu'
        return array_map("intval", $parts);
    }

public static function getMemoryUsage(): ?array
{
    try {
        $output = @shell_exec("free -m 2>/dev/null");
        if (!$output) {
            return null;
        }

        $lines = array_filter(explode("\n", $output));
        if (count($lines) < 2) {
            return null;
        }

        $memLine = array_values($lines)[1];
        $parts = preg_split('/\s+/', trim($memLine));

        if (count($parts) < 7) {
            return null;
        }

        $totalMem = (int)$parts[1];
        $usedMem = $totalMem - (int)$parts[6];
        $freeMem = (int)$parts[3];
        $usedPercentage = ($usedMem / $totalMem) * 100;

        $unit = $totalMem >= 1024 ? "GB" : "MB";
        $divider = $unit === "GB" ? 1024 : 1;
        $usage = round($usedPercentage, 2);

        if ($usage <= 25) {
            $backgroundColor = "bg-success";
        } elseif ($usage <= 50) {
            $backgroundColor = "bg-warning";
        } elseif ($usage <= 75) {
            $backgroundColor = "bg-warning";
        } else {
            $backgroundColor = "bg-danger";
        }

        return [
            "total_mem" => round($totalMem / $divider, 2),
            "used_mem" => round($usedMem / $divider, 2),
            "free_mem" => round($freeMem / $divider, 2),
            "used_percentage" => round($usedPercentage, 2),
            "unit" => $unit,
            "background" => $backgroundColor,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

    public static function getDiskUsage($path = "/"): ?array
    {
        $totalSpace = disk_total_space($path);
        $freeSpace = disk_free_space($path);
        $usedSpace = $totalSpace - $freeSpace;
        $usedPercentage = ($usedSpace / $totalSpace) * 100;
        $unit = $totalSpace >= 1024 ** 3 ? "GB" : "MB";
        $divider = $unit === "GB" ? 1024 ** 3 : 1024 ** 2;
        $totalSpaceFormatted = round($totalSpace / $divider, 2);
        $usedSpaceFormatted = round($usedSpace / $divider, 2);
        $freeSpaceFormatted = round($freeSpace / $divider, 2);
        $usedPercentage = round($usedPercentage, 2);

        $usage = $usedPercentage;
        if ($usage >= 0 && $usage <= 25) {
            $backgroundColor = "bg-success";
        } elseif ($usage > 25 && $usage <= 50) {
            $backgroundColor = "bg-info";
        } elseif ($usage > 50 && $usage <= 75) {
            $backgroundColor = "bg-warning";
        } elseif ($usage > 75 && $usage <= 100) {
            $backgroundColor = "bg-danger";
        }
        return [
            "total_space" => $totalSpaceFormatted,
            "used_space" => $usedSpaceFormatted,
            "free_space" => $freeSpaceFormatted,
            "used_percentage" => $usedPercentage,
            "unit" => $unit,
            "background" => $backgroundColor,
        ];
    }

    public static function toggleServer(string $idScreen, string $code): ?array
    {
        if (strpos($code, '"') !== false) {
            return [
                "success" => false,
                "message" => "Not allowed double quotes",
            ];
        }
        if (empty($idScreen) || empty($code)) {
            return [
                "success" => false,
                "message" => "Missing parameters",
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
            "message" => $mode,
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

    public static function formatBytes($folderSize)
    {
        $units = ["B", "KB", "MB", "GB", "TB"];
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

    public static function folderSize($file)
    {
        // usar o comando 'sl -sm' para listar o tamanho de todos os arquivos
        $size = 0;
        $command = "du -sb " . escapeshellarg($file);
        var_dump($command);
        $result = str_replace(["\t", "\r", "\n"], " ", trim(shell_exec($command)));
        $size = explode(" ", $result)[0];
        $size = (int)$size;
        return $size;
    }

    public static function countItensInPath($path)
    {
        $fileCount = 0;
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            } else {
                $fileCount++;
            }
        }
        return $fileCount;
    }

    public static function isCompressedFile($filename)
    {
        $compressedExtensions = ["7z", "rar", "zip", "tar", "gz", "bz2", "xz"];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $compressedExtensions);
    }

    public static function listCompressedFileContents($compressedFilePath)
    {
        $fileExtension = strtolower(pathinfo($compressedFilePath, PATHINFO_EXTENSION));
        $commands = [
            "zip" => "unzip -l",
            "rar" => "unrar l",
            "7z" => "7z l",
            "gz" => "tar -ztvf",
            "bz2" => "tar -jtvf",
            "xz" => "tar -Jtvf",
            "tar" => "tar -tvf",
        ];
        if (!isset($commands[$fileExtension])) {
            return [
                "success" => false,
                "message" => "Formato de arquivo não suportado.",
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
                "message" => "Erro ao listar o conteúdo do arquivo.",
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

    public static function extractCompressedFile($filePath, $destination)
    {
        if (!file_exists($filePath)) {
            return [
                "success" => false,
                "message" => "O arquivo não existe.",
            ];
        }
        $safeFilePath = escapeshellarg($filePath);
        $safeDestination = escapeshellarg($destination);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        switch ($extension) {
            case "zip":
                $command = "unzip -o $safeFilePath -d $safeDestination";
                break;
            case "tar":
                $command = "tar -xf $safeFilePath -C $safeDestination";
                break;
            case "gz":
                $command = "tar -xzf $safeFilePath -C $safeDestination";
                break;
            case "bz2":
                $command = "tar -xjf $safeFilePath -C $safeDestination";
                break;
            case "rar":
                $command = "unrar x -o+ $safeFilePath $safeDestination";
                break;
            case "xz":
                $command = "tar -xJf $safeFilePath -C $safeDestination";
                break;
            case "7z":
                $command = "7z x $safeFilePath -o $safeDestination";
                break;
            default:
                return [
                    "success" => false,
                    "message" => "Formato de arquivo não suportado.",
                ];
        }
        $output = shell_exec($command);
        if (strpos($output, "error") !== false) {
            return [
                "success" => false,
                "message" => "Erro ao extrair o arquivo.",
            ];
        }
        return [
            "success" => true,
            "message" => "Arquivo extraído com sucesso.",
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

    public static function listFiles($dir)
    {
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
            if (!file_exists($file)) continue;

            $realPath = realpath($file);

            // remove a base do caminho para manter a estrutura relativa
            $relativePath = ltrim(str_replace($base, '', $realPath), '/\\');

            //echo "📦 $realPath => $relativePath\n";

            $zip->addFile($realPath, $relativePath);
        }

        return $zip->close();
    }



    public static function isImage($filePath)
    {
        if (!file_exists($filePath)) {
            return false;
        }
        $size = @getimagesize($filePath);
        return is_array($size);
    }

    public static function isMediaFile($filePath)
    {
        $midias = ['png', 'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mpeg', 'mpg', '3gp', 'm4v', 'ts', 'vob', 'ogv', 'm2ts', 'mts', 'rmvb', 'asf', 'divx', 'xvid'];
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
            'wav', 'mp3', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'opus', 'aiff', 'alac', 'ape', 'mp2'
        ];
        
        if (in_array($value, $audio)) return true;
        return false;
    }

    public static function openPort(mixed $port): bool
    {
        if (!is_numeric($port)) {
            return false;
        }
        $port = (int)$port;
        $fp = @fsockopen("127.0.0.1", $port, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return false;
        }
        return true;
    }
}
