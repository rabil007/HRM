<?php

namespace App\Support\Logging;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final class ApplicationLogReader
{
    private const int MAX_READ_BYTES = 2_097_152;

    private const int MAX_ENTRIES = 2_000;

    /** @var list<string> */
    private const LEVELS = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency',
    ];

    /**
     * @return list<array{name: string, size_bytes: int, modified_at: string}>
     */
    public function listFiles(): array
    {
        $paths = glob(storage_path('logs/laravel*.log')) ?: [];

        usort($paths, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return array_values(array_map(function (string $path): array {
            return [
                'name' => basename($path),
                'size_bytes' => (int) filesize($path),
                'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
            ];
        }, $paths));
    }

    /**
     * @return array{
     *     entries: list<array{
     *         id: string,
     *         logged_at: string,
     *         environment: string,
     *         level: string,
     *         message: string,
     *         context: array<string, mixed>|null,
     *         stack: string|null
     *     }>,
     *     pagination: array{
     *         current_page: int,
     *         last_page: int,
     *         per_page: int,
     *         total: int,
     *         from: int|null,
     *         to: int|null
     *     },
     *     file: array{name: string, size_bytes: int, modified_at: string, truncated: bool}
     * }
     */
    public function paginate(
        ?string $fileName,
        ?string $level,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $files = $this->listFiles();

        if ($files === []) {
            return [
                'entries' => [],
                'pagination' => $this->emptyPagination($page, $perPage),
                'file' => [
                    'name' => '',
                    'size_bytes' => 0,
                    'modified_at' => '',
                    'truncated' => false,
                ],
            ];
        }

        $selectedFile = $this->resolveFileName($fileName, $files[0]['name']);
        $path = $this->resolvePath($selectedFile);
        $read = $this->readTail($path);
        $entries = $this->parseEntries($read['content']);

        if ($level !== null && $level !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['level'] === strtolower($level),
            ));
        }

        if ($search !== null && $search !== '') {
            $needle = Str::lower($search);
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $this->entryMatchesSearch($entry, $needle),
            ));
        }

        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min(max(1, $page), $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $slice = array_slice($entries, $offset, $perPage);

        $fileMeta = Arr::first($files, fn (array $file): bool => $file['name'] === $selectedFile) ?? $files[0];

        return [
            'entries' => $slice,
            'pagination' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : $offset + 1,
                'to' => $total === 0 ? null : min($offset + $perPage, $total),
            ],
            'file' => [
                'name' => $fileMeta['name'],
                'size_bytes' => $fileMeta['size_bytes'],
                'modified_at' => $fileMeta['modified_at'],
                'truncated' => $read['truncated'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function levels(): array
    {
        return self::LEVELS;
    }

    public function clearFile(string $fileName): void
    {
        $path = $this->resolvePath($fileName);

        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Could not clear the log file.');
        }
    }

    public function clearAll(): int
    {
        $files = $this->listFiles();

        foreach ($files as $file) {
            $this->clearFile($file['name']);
        }

        return count($files);
    }

    private function resolveFileName(?string $fileName, string $default): string
    {
        if ($fileName === null || $fileName === '') {
            return $default;
        }

        $this->resolvePath($fileName);

        return basename($fileName);
    }

    public function resolvePath(string $fileName): string
    {
        $basename = basename($fileName);
        $path = storage_path('logs/'.$basename);
        $logsDirectory = realpath(storage_path('logs'));

        if ($logsDirectory === false || ! is_file($path)) {
            throw new RuntimeException('Log file not found.');
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! str_starts_with($resolvedPath, $logsDirectory.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Log file not found.');
        }

        return $resolvedPath;
    }

    /**
     * @return array{content: string, truncated: bool}
     */
    private function readTail(string $path): array
    {
        $size = filesize($path);

        if ($size === false) {
            return ['content' => '', 'truncated' => false];
        }

        if ($size <= self::MAX_READ_BYTES) {
            $content = file_get_contents($path);

            return [
                'content' => is_string($content) ? $content : '',
                'truncated' => false,
            ];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['content' => '', 'truncated' => true];
        }

        fseek($handle, -self::MAX_READ_BYTES, SEEK_END);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return [
            'content' => $content,
            'truncated' => true,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     logged_at: string,
     *     environment: string,
     *     level: string,
     *     message: string,
     *     context: array<string, mixed>|null,
     *     stack: string|null
     * }>
     */
    private function parseEntries(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $chunks = preg_split('/\n(?=\[\d{4}-\d{2}-\d{2})/', trim($content)) ?: [];
        $entries = [];

        foreach ($chunks as $index => $chunk) {
            $parsed = $this->parseEntry($chunk);

            if ($parsed === null) {
                continue;
            }

            $entries[] = [
                'id' => sha1($parsed['logged_at'].'|'.$parsed['level'].'|'.$index.'|'.$parsed['message']),
                ...$parsed,
            ];
        }

        $entries = array_reverse($entries);

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        }

        return $entries;
    }

    /**
     * @return array{
     *     logged_at: string,
     *     environment: string,
     *     level: string,
     *     message: string,
     *     context: array<string, mixed>|null,
     *     stack: string|null
     * }|null
     */
    private function parseEntry(string $raw): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];

        if ($lines === [] || ! preg_match('/^\[([^\]]+)\]\s+([^.]+)\.(\w+):\s*(.*)$/', $lines[0], $matches)) {
            return null;
        }

        $messageLine = $matches[4];
        $context = null;
        $message = $messageLine;

        if (preg_match('/^(.*?)(\{.*\})$/s', $messageLine, $messageMatches) === 1) {
            $decoded = json_decode($messageMatches[2], true);

            if (is_array($decoded)) {
                $context = $decoded;
                $message = trim($messageMatches[1]);
            }
        }

        $stack = count($lines) > 1 ? implode("\n", array_slice($lines, 1)) : null;

        return [
            'logged_at' => $matches[1],
            'environment' => $matches[2],
            'level' => strtolower($matches[3]),
            'message' => $message,
            'context' => $context,
            'stack' => $stack === '' ? null : $stack,
        ];
    }

    /**
     * @param  array{
     *     logged_at: string,
     *     environment: string,
     *     level: string,
     *     message: string,
     *     context: array<string, mixed>|null,
     *     stack: string|null
     * }  $entry
     */
    private function entryMatchesSearch(array $entry, string $needle): bool
    {
        if (Str::contains(Str::lower($entry['message']), $needle)) {
            return true;
        }

        if ($entry['stack'] !== null && Str::contains(Str::lower($entry['stack']), $needle)) {
            return true;
        }

        if ($entry['context'] !== null) {
            $encoded = json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded) && Str::contains(Str::lower($encoded), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    private function emptyPagination(int $page, int $perPage): array
    {
        return [
            'current_page' => max(1, $page),
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }
}
