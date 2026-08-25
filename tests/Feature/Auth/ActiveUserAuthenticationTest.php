<?php

use App\Models\User;
use App\Support\Auth\RememberSession;
use App\Support\EmployeeDocuments\DocumentShareService;
use App\Support\Hikvision\HikvisionWebhookSignature;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

test('active users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('users cannot authenticate when status is not active', function (string $status) {
    $user = User::factory()->create(['status' => $status]);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
})->with(['inactive', 'suspended']);

test('disabled login errors match unknown-account errors and do not disclose status', function (string $status) {
    $disabled = User::factory()->create(['status' => $status]);

    $disabledResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => $disabled->email,
        'password' => 'password',
    ]);

    $disabledResponse->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();

    expect(strtolower((string) json_encode(session()->all())))
        ->not->toContain('inactive')
        ->not->toContain('suspended')
        ->not->toContain('disabled');

    $unknownResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => 'missing-user-'.uniqid().'@example.test',
        'password' => 'password',
    ]);

    $unknownResponse->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
})->with(['inactive', 'suspended']);

test('already authenticated active users can use protected routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('authenticated users are logged out when status becomes inactive or suspended', function (string $status) {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    $user->update(['status' => $status]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
})->with(['inactive', 'suspended']);

test('remember-me cannot restore an inactive user', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $recallerName = Auth::guard('web')->getRecallerName();
    $plainCookie = $response->getCookie($recallerName);
    $tokenBefore = $user->fresh()->remember_token;

    expect($plainCookie)->not->toBeNull()
        ->and(session(RememberSession::SESSION_KEY))->toBeTrue();

    $user->update(['status' => 'inactive']);

    expect($user->fresh()->remember_token)->not->toBe($tokenBefore);

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->withCookie($recallerName, (string) $plainCookie->getValue())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('remember token is invalidated when an admin disables the user', function () {
    $admin = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($admin, ['users.update']);
    $target = User::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    $originalToken = $target->remember_token;

    $this->actingAs($admin)
        ->put(route('organization.users.status', $target), [
            'status' => 'inactive',
        ])
        ->assertRedirect(route('organization.users'));

    expect($target->fresh()->status)->toBe('inactive')
        ->and($target->fresh()->remember_token)->not->toBe($originalToken)
        ->and($target->fresh()->remember_token)->not->toBeNull();

    $this->assertAuthenticatedAs($admin);
});

test('reactivated users can log in normally without restoring prior sessions', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ]);

    $this->assertGuest();

    $user->update(['status' => 'active']);

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('failed logins for disabled users still count toward login throttling', function () {
    $user = User::factory()->inactive()->create();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('login'));
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();

    $this->assertGuest();
});

test('active users with two factor enabled still complete login with a recovery code', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('inactive users cannot complete an existing two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);

    $user->update(['status' => 'inactive']);

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
    ])->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session('login.id'))->toBeNull();
});

test('inactive platform users cannot authenticate', function () {
    $user = User::factory()->inactive()->create();
    grantPlatformAccess($user, 'manage');

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('public login reset pages signed shares and webhooks remain available', function () {
    $this->get(route('login'))->assertOk();
    $this->get(route('password.request'))->assertOk();

    hikvisionSettings()->update([
        'webhook_verify_token' => 'abc12345',
        'webhook_enabled' => true,
    ]);

    $timestamp = (string) time();
    $batchId = 'active-user-auth-webhook';

    $this->get(route('webhooks.hikvision', hikvisionSettings()->public_id), [
        'X-Hook-Batch-Id' => $batchId,
        'X-Hook-Timestamp' => $timestamp,
    ])->assertOk()
        ->assertHeader('X-Hook-Signature', HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId));

    $inactive = User::factory()->inactive()->create();

    $this->actingAs($inactive)
        ->get(route('webhooks.hikvision', hikvisionSettings()->public_id), [
            'X-Hook-Batch-Id' => $batchId,
            'X-Hook-Timestamp' => $timestamp,
        ])->assertOk()
        ->assertHeader('X-Hook-Signature', HikvisionWebhookSignature::generate('abc12345', $timestamp, $batchId));

    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');
    $shares = app(DocumentShareService::class);
    $share = $shares->createFilesShare($employee, [$document->id], $company->id, null);

    $this->get($shares->shareUrl($share))->assertOk();

    $this->actingAs($inactive)
        ->get($shares->shareUrl($share))
        ->assertOk();
});
