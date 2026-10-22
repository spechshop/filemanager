<?php
declare(strict_types=1);
namespace plugins\Request;

final class safeFiles
{
    public static function remove(string $path, string $token): void
    {
        if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
        $path = securityPolicy::path($path, $token, false);
        if (is_dir($path)) {
            foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') self::remove($path . '/' . $name, $token);
            if (!rmdir($path)) throw new \RuntimeException('delete');
        } elseif (!unlink($path)) throw new \RuntimeException('delete');
    }

    public static function copy(string $source, string $target, string $token): void
    {
        if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
        $source = securityPolicy::path($source, $token);
        $target = securityPolicy::path($target, $token, false);
        if (securityPolicy::within($target, $source)) throw new \DomainException('recursive copy');
        if (is_dir($source)) {
            if (!is_dir($target) && !mkdir($target, 0750)) throw new \RuntimeException('copy');
            foreach (scandir($source) as $name) if ($name !== '.' && $name !== '..') self::copy($source . '/' . $name, $target . '/' . $name, $token);
        } elseif (!copy($source, $target)) throw new \RuntimeException('copy');
    }

    /** Read metadata for every entry before creating anything. Never extract links. */
    public static function extract(string $archive, string $target, string $token): array
    {
        $archive = securityPolicy::path($archive, $token);
        $target = securityPolicy::path($target, $token);
        $temporary = null;
        if (preg_match('/\.xz$/iD', $archive)) {
            $temporary = tempnam(sys_get_temp_dir(), 'fm-xz-');
            if ($temporary === false) throw new \RuntimeException('archive temporary');
            try {
                $process = null;
                $input = self::decoder(['xz', '--decompress', '--stdout', '--', $archive], $process);
                $output = fopen($temporary, 'wb');
                try {
                    stream_copy_to_stream($input, $output, 536870913);
                    $size = ftell($output);
                } finally { fclose($input); fclose($output); }
                if (proc_close($process) !== 0 || $size > 536870912) throw new \DomainException('archive size');
                // PharData needs a TAR suffix; the file is private and never extracted by xz.
                rename($temporary, $temporary . '.tar'); $temporary .= '.tar';
                return self::extractDecoded($temporary, $target, $token);
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        return self::extractDecoded($archive, $target, $token);
    }

    /** Decoder subprocesses emit bytes only; they never receive a filesystem extraction directory. */
    private static function decoder(array $args, &$process)
    {
        $pipes = [];
        $process = proc_open($args, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => getenv('PATH'), 'LC_ALL' => 'C']);
        if (!is_resource($process)) throw new \RuntimeException('archive decoder');
        return $pipes[1];
    }

    private static function extractDecoded(string $archive, string $target, string $token): array
    {
        $entries = []; $total = 0;
        $zip = null;
        if (strtolower(pathinfo($archive, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($archive, \ZipArchive::RDONLY) !== true) throw new \DomainException('archive');
            try {
                if ($zip->numFiles > 10000) throw new \DomainException('archive size');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i); $opsys = 0; $attributes = 0;
                    $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                    $mode = ($attributes >> 16) & 0170000;
                    if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) throw new \DomainException('archive link');
                    $entries[] = ['name' => $stat['name'], 'size' => $stat['size'], 'dir' => str_ends_with($stat['name'], '/'), 'index' => $i];
                }
            } catch (\Throwable $e) { $zip->close(); throw $e; }
        } elseif (preg_match('/\.(7z|rar)$/iD', $archive)) {
            $process = null;
            $input = self::decoder(['7z', 'l', '-slt', '-ba', '--', $archive], $process);
            try { $listing = stream_get_contents($input, 8388609); }
            finally { fclose($input); }
            if (proc_close($process) !== 0 || strlen($listing) > 8388608) throw new \DomainException('archive listing');
            foreach (preg_split('/\r?\n\r?\n/', trim($listing, "\r\n")) as $block) {
                $fields = [];
                foreach (preg_split('/\r?\n/', $block) as $line) {
                    $pair = explode(' = ', $line, 2);
                    if (count($pair) !== 2 || isset($fields[$pair[0]])) throw new \DomainException('archive metadata');
                    $fields[$pair[0]] = $pair[1];
                }
                if (!isset($fields['Path'], $fields['Size']) || !ctype_digit($fields['Size'])
                    || isset($fields['Symbolic Link']) || isset($fields['Hard Link'])
                    || preg_match('/(?:^| )l[rwx-]{9}/', $fields['Attributes'] ?? '')) throw new \DomainException('archive link');
                $entries[] = ['name' => $fields['Path'], 'size' => (int) $fields['Size'], 'dir' => ($fields['Folder'] ?? '') === '+' || str_starts_with($fields['Attributes'] ?? '', 'D'), 'decoder' => true];
                if (count($entries) > 10000) throw new \DomainException('archive size');
            }
        } else {
            // PharData reads TAR and compressed TAR without invoking a filesystem extractor.
            if (!preg_match('/\.(tar|tgz|tar\.gz|tar\.bz2|gz|bz2)$/iD', $archive)) throw new \DomainException('archive format');
            $phar = new \PharData($archive);
            foreach (new \RecursiveIteratorIterator($phar, \RecursiveIteratorIterator::SELF_FIRST) as $key => $item) {
                if ($item->isLink() || (!$item->isFile() && !$item->isDir())) throw new \DomainException('archive link');
                $name = substr($key, strlen('phar://' . $archive . '/'));
                $entries[] = ['name' => $name, 'size' => $item->getSize(), 'dir' => $item->isDir(), 'source' => $key];
                if (count($entries) > 10000) throw new \DomainException('archive size');
            }
        }
        try {
            foreach ($entries as &$entry) {
                $name = rtrim($entry['name'], '/');
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, ':')) throw new \DomainException('archive path');
                $entry['target'] = securityPolicy::path($target . '/' . $name, $token, false);
                $total += $entry['size'];
                if ($total > 536870912) throw new \DomainException('archive size');
            }
            unset($entry);
            // Complete validation precedes mkdir, including for malicious late members.
            foreach ($entries as $entry) {
                if (!securityPolicy::validToken($token)) throw new \DomainException('revoked');
                $out = securityPolicy::path($entry['target'], $token, false);
                $parent = $entry['dir'] ? $out : dirname($out);
                if (!is_dir($parent) && !mkdir($parent, 0750, true)) throw new \RuntimeException('archive directory');
                if ($entry['dir']) continue;
                $process = null;
                $input = isset($entry['decoder'])
                    ? self::decoder(['7z', 'x', '-so', '-spd', '--', $archive, $entry['name']], $process)
                    : ($zip ? $zip->getStream($entry['name']) : fopen($entry['source'], 'rb'));
                $output = fopen($out, 'wb');
                if (!$input || !$output) throw new \RuntimeException('archive stream');
                try {
                    $written = stream_copy_to_stream($input, $output, $entry['size'] + 1);
                    if ($written !== $entry['size']) throw new \DomainException('archive size');
                } finally {
                    fclose($input); fclose($output);
                    if (is_resource($process) && proc_close($process) !== 0) throw new \DomainException('archive decoder');
                }
            }
        } finally { if ($zip) $zip->close(); }
        return ['success' => true, 'message' => 'Arquivo extraído com sucesso.'];
    }
}
