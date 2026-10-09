<?php

namespace MlSolutions\NovaLogsView\Support;

use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

final class LogFileRepository
{
    /** @return array<int, array{id: string, name: string, size: int, modified_at: string}> */
    public function files(): array
    {
        $root = $this->root();

        if ($root === null) {
            return [];
        }

        $files = [];

        try {
            $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            return [];
        }

        foreach ($iterator as $file) {
            try {
                if (! $file instanceof SplFileInfo || ! $this->isAllowed($file, $root)) {
                    continue;
                }

                $files[] = [
                    'id' => $this->id($file->getFilename()),
                    'name' => $file->getFilename(),
                    'size' => max(0, (int) $file->getSize()),
                    'modified_at' => date(DATE_ATOM, (int) $file->getMTime()),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp($right['modified_at'], $left['modified_at']));

        return array_slice($files, 0, max(1, (int) config('nova-logs-view.max_files', 31)));
    }

    /** @return array{content: string, truncated: bool, start_offset: int}|null */
    public function read(string $id): ?array
    {
        $file = collect($this->files())->firstWhere('id', $id);

        if (! is_array($file)) {
            return null;
        }

        $root = $this->root();

        if ($root === null) {
            return null;
        }

        $path = $root.DIRECTORY_SEPARATOR.$file['name'];
        $real = realpath($path);

        if ($real === false || ! $this->withinRoot($real, $root) || is_link($path) || ! is_readable($real)) {
            return null;
        }

        $size = @filesize($real);

        if ($size === false) {
            return null;
        }

        $size = max(0, $size);
        $limit = max(64 * 1024, (int) config('nova-logs-view.max_bytes_per_file', 4 * 1024 * 1024));
        $start = max(0, $size - $limit);
        $handle = @fopen($real, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($start > 0) {
                fseek($handle, $start);
            }

            $content = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (! is_string($content)) {
            return null;
        }

        if ($start > 0 && ($newline = strpos($content, "\n")) !== false) {
            $start += $newline + 1;
            $content = substr($content, $newline + 1);
        }

        return ['content' => $content, 'truncated' => $start > 0, 'start_offset' => $start];
    }

    private function root(): ?string
    {
        $configured = (string) config('nova-logs-view.root_path', storage_path('logs'));
        $root = realpath($configured);

        return $root !== false && is_dir($root) && is_readable($root) ? rtrim($root, DIRECTORY_SEPARATOR) : null;
    }

    private function isAllowed(SplFileInfo $file, string $root): bool
    {
        if (! $file->isFile() || $file->isLink() || str_starts_with($file->getFilename(), '.')) {
            return false;
        }

        $real = $file->getRealPath();

        if ($real === false || ! $this->withinRoot($real, $root) || ! is_readable($real)) {
            return false;
        }

        foreach ((array) config('nova-logs-view.patterns', ['*.log']) as $pattern) {
            if (fnmatch((string) $pattern, $file->getFilename(), FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function withinRoot(string $path, string $root): bool
    {
        return $path !== $root && Str::startsWith($path, $root.DIRECTORY_SEPARATOR);
    }

    private function id(string $name): string
    {
        return substr(hash('sha256', $name), 0, 24);
    }
}
