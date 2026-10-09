<?php

namespace App\Support\AppRefresh;

/**
 * Stable identifier for the currently deployed application release.
 *
 * Production deploys write storage/app/deploy/revision.sha (see deploy.yml).
 * Local/dev falls back to the Vite build manifest fingerprint when present.
 */
final class DeployedApplicationVersion
{
    public static function current(): string
    {
        $fromStamp = self::fromDeployStamp();

        if ($fromStamp !== null) {
            return $fromStamp;
        }

        $configured = config('app.deploy_version');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $fromManifest = self::fromViteManifest();

        if ($fromManifest !== null) {
            return $fromManifest;
        }

        return 'dev';
    }

    public static function stampPath(): string
    {
        return storage_path('app/deploy/revision.sha');
    }

    private static function fromDeployStamp(): ?string
    {
        $path = self::stampPath();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $value = trim((string) file_get_contents($path));

        return $value !== '' ? $value : null;
    }

    private static function fromViteManifest(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest) || ! is_readable($manifest)) {
            return null;
        }

        $hash = hash_file('sha256', $manifest);

        return is_string($hash) && $hash !== ''
            ? 'manifest-'.substr($hash, 0, 16)
            : null;
    }
}
