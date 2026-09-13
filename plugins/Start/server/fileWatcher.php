<?php

namespace plugins\Start;

/** A bounded polling fallback: only application directories, never dependency trees. */
final class fileWatcher
{
    private array $files = [];
    private bool $initialized = false;
    private const IGNORED = ['vendor', 'node_modules', 'files', 'stubs', 'terminals', '.git'];

    public function __construct(private array $roots, private array $extensions)
    {
    }

    /** Returns changed paths; the first snapshot establishes the baseline. */
    public function changes(): array
    {
        if ($this->extensions === []) {
            return [];
        }
        $next = [];
        $changed = [];
        $sampledAt = time();
        foreach ($this->roots as $root) {
            $this->scan($root, $next, $changed, $sampledAt);
        }
        foreach (array_diff_key($this->files, $next) as $path => $_) {
            $changed[] = $path;
        }
        $this->files = $next;
        if (!$this->initialized) {
            $this->initialized = true;
            return [];
        }
        return $changed;
    }

    private function scan(string $directory, array &$next, array &$changed, int $sampledAt): void
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            // An unreadable directory is not evidence that all its files vanished.
            // A missing directory, on the other hand, must report deletions.
            clearstatcache(true, $directory);
            if (is_dir($directory)) {
                foreach ($this->files as $path => $record) {
                    if (str_starts_with($path, $directory . '/')) {
                        $next[$path] = $record;
                    }
                }
            }
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::IGNORED, true)) {
                continue;
            }
            $path = $directory . '/' . $name;
            clearstatcache(true, $path);
            // Do not follow directory symlinks into dependencies or outside the project.
            if (is_dir($path)) {
                if (!is_link($path)) {
                    $this->scan($path, $next, $changed, $sampledAt);
                }
                continue;
            }
            if (!in_array(pathinfo($name, PATHINFO_EXTENSION), $this->extensions, true)) {
                continue;
            }
            $stat = @stat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                continue;
            }
            $metadata = [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino'], $stat['dev']];
            $previous = $this->files[$path] ?? null;
            // PHP exposes second-resolution timestamps. Recheck recent snapshots
            // once the second has elapsed, including same-size rapid saves.
            $recent = $previous !== null
                && max($previous['metadata'][0], $previous['metadata'][1]) >= $previous['sampledAt'];
            if ($previous !== null && $previous['metadata'] === $metadata && !$recent) {
                $next[$path] = $previous;
                continue;
            }
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                if ($previous !== null) {
                    $next[$path] = $previous;
                }
                continue;
            }
            $next[$path] = ['metadata' => $metadata, 'hash' => $hash, 'sampledAt' => $sampledAt];
            if ($previous === null || $previous['hash'] !== $hash) {
                $changed[] = $path;
            }
        }
    }
}
