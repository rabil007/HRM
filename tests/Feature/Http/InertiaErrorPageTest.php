<?php

use App\Exceptions\PrivilegedTwoFactorRequiredException;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

test('unknown html route returns inertia 404 with correct status', function () {
    $response = $this->get('/definitely-not-a-real-oms-route-'.uniqid());

    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page) => $page->component('errors/404'));
});

test('forbidden html route returns inertia 403', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/organization/companies');

    $response->assertForbidden();
    $response->assertInertia(fn (Assert $page) => $page->component('errors/403'));
});

test('forbidden non-html request does not return an inertia json payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/organization/companies', [
        'Accept' => 'image/jpeg',
    ]);

    $response->assertForbidden();
    expect((string) $response->headers->get('Content-Type'))
        ->not->toContain('application/json');
});

test('json api client keeps json error contract for forbidden requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson('/organization/companies');

    $response->assertForbidden();
    $response->assertJsonStructure(['message']);
});

test('guest html request to protected route redirects to login instead of inertia 401 page', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect(route('login'));
});

test('error pages include browser security headers', function () {
    $response = $this->get('/definitely-not-a-real-oms-route-'.uniqid());

    $response->assertNotFound();
    assertBrowserSecurityHeaders($response);
});

test('inertia error pages are registered for supported statuses in testing', function () {
    Route::middleware('web')->get('/_test/inertia-error/{status}', function (int $status) {
        abort($status);
    });

    foreach ([400, 401, 404, 419, 429, 503] as $status) {
        $response = $this->get('/_test/inertia-error/'.$status);

        $response->assertStatus($status);
        $response->assertInertia(fn (Assert $page) => $page->component('errors/'.$status));
    }
});

test('429 inertia page receives retry-after seconds when provided', function () {
    Route::middleware('web')->get('/_test/inertia-error-429', function () {
        throw new TooManyRequestsHttpException(42);
    });

    $response = $this->get('/_test/inertia-error-429');

    $response->assertStatus(429);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('errors/429')
        ->where('retry_after_seconds', 42)
    );
});

test('production html 500 does not expose exception messages', function () {
    $originalDebug = config('app.debug');

    config(['app.debug' => false]);

    Route::middleware('web')->get('/_test/inertia-error-500-secret', function () {
        throw new RuntimeException('SECRET_SQL_DETAIL_SHOULD_NOT_LEAK');
    });

    try {
        $response = $this->get('/_test/inertia-error-500-secret');

        $response->assertStatus(500);
        $response->assertInertia(fn (Assert $page) => $page->component('errors/500'));
        expect($response->getContent())->not->toContain('SECRET_SQL_DETAIL_SHOULD_NOT_LEAK');
    } finally {
        config(['app.debug' => $originalDebug]);
    }
});

test('token mismatch exception renders inertia 419 for html requests', function () {
    Route::middleware('web')->get('/_test/inertia-token-mismatch', function () {
        throw new TokenMismatchException;
    });

    $this->get('/_test/inertia-token-mismatch')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page->component('errors/419'));
});

test('privileged two factor exception keeps custom redirect render', function () {
    $response = (new PrivilegedTwoFactorRequiredException)->render(
        Request::create('/settings/security', 'POST'),
    );

    expect($response)->toBeInstanceOf(RedirectResponse::class);
});

test('missing tenant-scoped record remains 404 not forbidden', function () {
    ['company' => $company] = makeDocumentFixtures();
    ['company' => $otherCompany] = makeDocumentFixtures();

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $foreignEmployee = Employee::factory()->for($otherCompany)->create();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/employees/{$foreignEmployee->id}")
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('errors/404'));
});
