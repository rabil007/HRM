<?php

namespace App\Support\Security;

final class ContentSecurityPolicy
{
    /**
     * @return array<string, list<string>>
     */
    public static function directives(?string $environment = null): array
    {
        $environment ??= app()->environment();

        $scriptSrc = ["'self'"];
        $styleSrc = ["'self'", "'unsafe-inline'"];
        $fontSrc = ["'self'"];
        $imgSrc = ["'self'", 'data:', 'blob:'];
        $connectSrc = ["'self'"];

        if ($environment === 'local') {
            $scriptSrc[] = "'unsafe-inline'";

            foreach (self::viteDevOrigins() as $origin) {
                if (str_starts_with($origin, 'ws:') || str_starts_with($origin, 'wss:')) {
                    $connectSrc[] = $origin;

                    continue;
                }

                $scriptSrc[] = $origin;
                $styleSrc[] = $origin;
                $fontSrc[] = $origin;
                $imgSrc[] = $origin;
                $connectSrc[] = $origin;
            }
        }

        return [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'script-src' => $scriptSrc,
            'style-src' => $styleSrc,
            'img-src' => $imgSrc,
            'font-src' => $fontSrc,
            'connect-src' => $connectSrc,
            'worker-src' => ["'self'", 'blob:'],
            'frame-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'media-src' => ["'self'"],
        ];
    }

    public static function headerValue(?string $environment = null): string
    {
        return self::compile(self::directives($environment));
    }

    /**
     * @param  array<string, list<string>>  $directives
     */
    public static function compile(array $directives): string
    {
        $parts = [];

        foreach ($directives as $name => $values) {
            $parts[] = $values === []
                ? $name
                : $name.' '.implode(' ', $values);
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function parse(string $header): array
    {
        $directives = [];

        foreach (array_map(trim(...), explode(';', $header)) as $part) {
            if ($part === '') {
                continue;
            }

            $tokens = preg_split('/\s+/', $part) ?: [];
            $name = array_shift($tokens);

            if ($name === null || $name === '') {
                continue;
            }

            $directives[$name] = array_values($tokens);
        }

        return $directives;
    }

    /**
     * @return list<string>
     */
    public static function viteDevOrigins(): array
    {
        $origins = config('security.headers.csp.vite_dev_origins', []);

        if (! is_array($origins)) {
            return [];
        }

        $allowed = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            $origin = trim($origin);

            if ($origin === '' || ! self::isTrustedViteOrigin($origin)) {
                continue;
            }

            $allowed[] = $origin;
        }

        return array_values(array_unique($allowed));
    }

    private static function isTrustedViteOrigin(string $origin): bool
    {
        if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($origin);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return false;
        }

        return $host === '127.0.0.1'
            || $host === 'localhost'
            || $host === '::1'
            || str_ends_with($host, '.test');
    }
}
