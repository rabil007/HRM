<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;

final class AuthenticateActiveUser
{
    /**
     * Resolve a user for Fortify login using the same identity lookup as the
     * default guard provider, then require `users.status = active`.
     *
     * Returning null produces Fortify's normal invalid-credentials response
     * (`auth.failed`) so callers cannot distinguish a missing account from a
     * disabled one.
     */
    public function __invoke(Request $request): ?User
    {
        $provider = Auth::guard(config('fortify.guard'))->getProvider();
        $credentials = $request->only(Fortify::username(), 'password');
        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        if (! $user instanceof User || ! UserAccountStatus::allowsAuthentication($user)) {
            return null;
        }

        $this->rehashPasswordIfRequired($provider, $user, $credentials);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function rehashPasswordIfRequired(UserProvider $provider, User $user, array $credentials): void
    {
        if (! config('hashing.rehash_on_login', true)) {
            return;
        }

        if (! method_exists($provider, 'rehashPasswordIfRequired')) {
            return;
        }

        if (! $provider instanceof EloquentUserProvider) {
            return;
        }

        $provider->rehashPasswordIfRequired($user, $credentials);
    }
}
