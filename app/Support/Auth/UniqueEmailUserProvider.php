<?php

namespace App\Support\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Fortify;

final class UniqueEmailUserProvider extends EloquentUserProvider
{
    /**
     * Resolve a user by credentials only when the Fortify username maps to
     * exactly one non-deleted User. Multiple matches fail closed (null).
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $username = Fortify::username();
        $identity = $credentials[$username] ?? null;

        if (is_string($identity) && $identity !== '') {
            return app(UserEmailIdentity::class)->findUnique($identity);
        }

        return parent::retrieveByCredentials($credentials);
    }
}
