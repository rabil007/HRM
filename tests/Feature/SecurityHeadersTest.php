<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

test('login pages send the enforced browser security header set', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    assertBrowserSecurityHeaders($response);
    $response->assertSee('js/appearance.js', false)
        ->assertDontSee('cdn.tailwindcss.com', false)
        ->assertDontSee("const appearance = '", false);
    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('security settings pages send framing and nosniff protections', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();
    assertBrowserSecurityHeaders($response);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('forbidden html responses still receive security headers', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $response = $this->actingAs($user)->get('/log');

    $response->assertForbidden();
    assertBrowserSecurityHeaders($response);
});

test('platform log pages keep no-store caching', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    $response = $this->actingAs($user)->get('/log');

    $response->assertOk();
    assertBrowserSecurityHeaders($response);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('platform job and database viewer pages keep no-store caching', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    config(['platform.database_viewer.enabled' => true]);

    $jobs = $this->actingAs($user)->get(route('jobs.index'));
    $jobs->assertOk();
    assertBrowserSecurityHeaders($jobs);
    expect((string) $jobs->headers->get('Cache-Control'))->toContain('no-store');

    $mysql = $this->actingAs($user)->get(route('mysql.index'));
    $mysql->assertOk();
    assertBrowserSecurityHeaders($mysql);
    expect((string) $mysql->headers->get('Cache-Control'))->toContain('no-store');
});

test('production https responses receive hsts without preload', function () {
    $this->app['env'] = 'production';

    $response = $this->withServerVariables(['HTTPS' => 'on'])->get(route('login'));

    $response->assertOk();
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    expect((string) $response->headers->get('Strict-Transport-Security'))->not->toContain('preload');
    assertBrowserSecurityHeaders($response);
});

test('https outside production does not receive hsts', function () {
    $response = $this->withServerVariables(['HTTPS' => 'on'])->get(route('login'));

    $response->assertOk();
    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
    assertBrowserSecurityHeaders($response);
});

test('local https responses do not receive hsts', function () {
    $this->app['env'] = 'local';

    $response = $this->withServerVariables(['HTTPS' => 'on'])->get(route('login'));

    $response->assertOk();
    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('non-secure requests never receive hsts even in production', function () {
    $this->app['env'] = 'production';

    $request = Request::create('http://oms-hrm.test/login', 'GET');
    $response = app(SecurityHeaders::class)->apply($request, new SymfonyResponse('ok'));

    expect($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

test('csp report-only mode emits report-only instead of an enforcing policy', function () {
    config(['security.headers.csp.report_only' => true]);

    $response = $this->get(route('login'));

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toBeNull();

    $reportOnly = (string) $response->headers->get('Content-Security-Policy-Report-Only');
    $directives = ContentSecurityPolicy::parse($reportOnly);

    expect($directives['object-src'] ?? [])->toBe(["'none'"])
        ->and($directives['script-src'] ?? [])->not->toContain("'unsafe-eval'");
});

test('session cookies stay httponly with lax same-site', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

test('x-frame-options defaults to sameorigin and can be omitted or customized', function () {
    $response = $this->get(route('login'));
    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    config(['security.headers.x_frame_options' => false]);
    $responseDisabled = $this->get(route('login'));
    $responseDisabled->assertOk();
    $responseDisabled->assertHeaderMissing('X-Frame-Options');

    config(['security.headers.x_frame_options' => 'DENY']);
    $responseDeny = $this->get(route('login'));
    $responseDeny->assertOk();
    $responseDeny->assertHeader('X-Frame-Options', 'DENY');
});

test('csp frame-ancestors includes configured framing parents on responses', function () {
    config(['security.headers.csp.frame_ancestors' => "'self' https://portal.overseas-ms.com"]);

    $response = $this->get(route('login'));
    $response->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    $directives = ContentSecurityPolicy::parse($csp);

    expect($directives['frame-ancestors'] ?? [])->toBe(["'self'", 'https://portal.overseas-ms.com']);
});
