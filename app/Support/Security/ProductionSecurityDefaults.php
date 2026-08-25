<?php

namespace App\Support\Security;

/**
 * Repository-enforced production safety nets that do not depend on operators
 * remembering optional .env keys. Live production values still cannot be
 * proven from GitHub; see docs/security-headers.md.
 */
final class ProductionSecurityDefaults
{
    public function apply(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        config(['app.debug' => false]);

        $secure = config('session.secure');

        if ($secure === null || $secure === '') {
            config(['session.secure' => true]);
        }
    }
}
