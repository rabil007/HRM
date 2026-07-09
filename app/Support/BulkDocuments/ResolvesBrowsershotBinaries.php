<?php

namespace App\Support\BulkDocuments;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ResolvesBrowsershotBinaries
{
    /**
     * @return array{node: string, npm: string, chrome: ?string}
     */
    public static function resolve(): array
    {
        $nodeBinary = self::nodeBinary();
        $npmBinary = self::npmBinary();

        if ($nodeBinary === null || $npmBinary === null) {
            throw new RuntimeException(
                'Node.js was not found on this server. Install Node.js on the queue worker host or set BROWSERSHOT_NODE_BINARY and BROWSERSHOT_NPM_BINARY in .env.',
            );
        }

        return [
            'node' => $nodeBinary,
            'npm' => $npmBinary,
            'chrome' => self::chromePath(),
        ];
    }

    public static function nodeBinary(): ?string
    {
        return self::resolveBinary(
            config('services.browsershot.node_binary'),
            'node',
        );
    }

    public static function npmBinary(): ?string
    {
        return self::resolveBinary(
            config('services.browsershot.npm_binary'),
            'npm',
        );
    }

    public static function chromePath(): ?string
    {
        $configured = config('services.browsershot.chrome_path');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $cacheDirs = array_values(array_unique([
            ConfiguresBrowsershotEnvironment::resolveCacheDir(),
            storage_path('app/puppeteer'),
        ]));

        foreach ($cacheDirs as $cacheDir) {
            foreach (glob($cacheDir.'/chrome-headless-shell/*/chrome-headless-shell-*/chrome-headless-shell') ?: [] as $path) {
                if (is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private static function resolveBinary(mixed $configured, string $name): ?string
    {
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        foreach (self::candidatePaths($name) as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return self::which($name);
    }

    /**
     * @return list<string>
     */
    private static function candidatePaths(string $name): array
    {
        $paths = [];

        foreach (glob('/opt/alt/alt-nodejs*/root/usr/bin/'.$name) ?: [] as $path) {
            $paths[] = $path;
        }

        rsort($paths);

        $paths[] = '/opt/homebrew/bin/'.$name;

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);

        if (is_string($home) && $home !== '') {
            foreach (glob($home.'/.nvm/versions/node/*/bin/'.$name) ?: [] as $path) {
                $paths[] = $path;
            }

            foreach (glob($home.'/Library/Application Support/Herd/config/nvm/versions/node/*/bin/'.$name) ?: [] as $path) {
                $paths[] = $path;
            }

            rsort($paths);
        }

        $paths[] = '/usr/local/bin/'.$name;
        $paths[] = '/usr/bin/'.$name;

        return $paths;
    }

    private static function which(string $name): ?string
    {
        $searchPaths = array_values(array_unique(array_filter(array_merge(
            explode(':', getenv('PATH') ?: ''),
            ['/usr/local/bin', '/usr/bin'],
            array_map(static fn (string $path): string => dirname($path), self::candidatePaths($name)),
        ))));

        $process = new Process(
            ['sh', '-c', 'command -v '.escapeshellarg($name)],
            null,
            ['PATH' => implode(':', $searchPaths)],
        );

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $resolved = trim($process->getOutput());

        return $resolved !== '' && is_executable($resolved) ? $resolved : null;
    }
}
