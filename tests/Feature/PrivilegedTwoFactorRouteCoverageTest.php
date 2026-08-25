<?php

use App\Support\Auth\PrivilegedTwoFactorPolicy;
use Illuminate\Support\Facades\Route;

test('mutating routes that authorize a catalogued privileged permission also use privileged.2fa', function () {
    $unprotected = [];

    foreach (Route::getRoutes() as $route) {
        $methods = array_map(strtoupper(...), $route->methods());

        if (array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
            continue;
        }

        $name = (string) $route->getName();

        if ($name !== '' && str_ends_with($name, '.test')) {
            continue;
        }

        $middleware = collect($route->gatherMiddleware())
            ->filter(fn (mixed $item): bool => is_string($item))
            ->values()
            ->all();

        $hasPrivilegedTwoFactor = collect($middleware)->contains(
            fn (string $item): bool => $item === 'privileged.2fa' || str_starts_with($item, 'privileged.2fa:'),
        );

        foreach ($middleware as $item) {
            $permission = cataloguedPermissionFromMiddleware($item);

            if ($permission === null) {
                continue;
            }

            if ($hasPrivilegedTwoFactor) {
                continue;
            }

            $unprotected[] = sprintf(
                '%s %s (%s) permission=%s',
                implode('|', $methods),
                $name !== '' ? $name : $route->uri(),
                $route->uri(),
                $permission,
            );
        }
    }

    expect($unprotected)->toBeEmpty();
});

test('employee user creation is in the privileged two-factor route set', function () {
    $route = Route::getRoutes()->getByName('organization.employees.user.store');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('can:users.create')
        ->and($route->gatherMiddleware())->toContain('privileged.2fa');
});

function cataloguedPermissionFromMiddleware(string $middleware): ?string
{
    $ability = null;

    if (str_starts_with($middleware, 'can:')) {
        $ability = substr($middleware, 4);
    } elseif (str_contains($middleware, 'Authorize:')) {
        $ability = substr($middleware, strpos($middleware, 'Authorize:') + strlen('Authorize:'));
    }

    if (! is_string($ability) || $ability === '') {
        return null;
    }

    $ability = explode(',', $ability)[0];

    if (! in_array($ability, PrivilegedTwoFactorPolicy::PERMISSIONS, true)) {
        return null;
    }

    return $ability;
}
