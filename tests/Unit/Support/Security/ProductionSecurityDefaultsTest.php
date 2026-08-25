<?php

use App\Support\Security\ProductionSecurityDefaults;
use Illuminate\Http\Request;

test('session cookies are http only, same site lax, and json serialized', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.serialization'))->toBe('json');
});

test('production boot forces debug off and secure cookies when unset', function () {
    $previousEnv = $this->app['env'];
    $previousDebug = config('app.debug');
    $previousSecure = config('session.secure');

    $this->app['env'] = 'production';
    config([
        'app.debug' => true,
        'session.secure' => null,
    ]);

    app(ProductionSecurityDefaults::class)->apply();

    expect(config('app.debug'))->toBeFalse()
        ->and(config('session.secure'))->toBeTrue();

    $this->app['env'] = $previousEnv;
    config([
        'app.debug' => $previousDebug,
        'session.secure' => $previousSecure,
    ]);
});

test('production boot does not override an explicit insecure session cookie setting', function () {
    $previousEnv = $this->app['env'];
    $previousSecure = config('session.secure');

    $this->app['env'] = 'production';
    config(['session.secure' => false]);

    app(ProductionSecurityDefaults::class)->apply();

    expect(config('session.secure'))->toBeFalse();

    $this->app['env'] = $previousEnv;
    config(['session.secure' => $previousSecure]);
});

test('non-production boot does not force debug or secure cookies', function () {
    $previousEnv = $this->app['env'];
    $previousDebug = config('app.debug');
    $previousSecure = config('session.secure');

    $this->app['env'] = 'testing';
    config([
        'app.debug' => true,
        'session.secure' => null,
    ]);

    app(ProductionSecurityDefaults::class)->apply();

    expect(config('app.debug'))->toBeTrue()
        ->and(config('session.secure'))->toBeNull();

    $this->app['env'] = $previousEnv;
    config([
        'app.debug' => $previousDebug,
        'session.secure' => $previousSecure,
    ]);
});

test('forwarded proto is ignored when trusted proxies are not configured', function () {
    $request = Request::create('http://oms-hrm.test/login', 'GET', server: [
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    expect($request->secure())->toBeFalse();
});
