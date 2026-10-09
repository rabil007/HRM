<?php

use App\Models\User;
use App\Support\AppRefresh\AuthorizationRevision;
use App\Support\AppRefresh\DeployedApplicationVersion;
use Illuminate\Support\Facades\File;

test('guests cannot read the app version endpoint', function () {
    $this->getJson(route('app.version'))
        ->assertUnauthorized();
});

test('authenticated users receive the deployed version and authorization revision', function () {
    $user = User::factory()->create();
    $company = createAppRefreshCompany();

    $user->companies()->syncWithoutDetaching([
        $company->id => ['status' => 'active'],
    ]);

    $stampDir = dirname(DeployedApplicationVersion::stampPath());
    File::ensureDirectoryExists($stampDir);
    File::put(DeployedApplicationVersion::stampPath(), "deploy-abc123\n");

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->getJson(route('app.version'));

    $response->assertOk()
        ->assertJsonPath('version', 'deploy-abc123')
        ->assertJsonPath('update_available', false)
        ->assertJsonPath(
            'authorization_revision',
            AuthorizationRevision::current($user->fresh(), $company->id),
        );

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    File::delete(DeployedApplicationVersion::stampPath());
});

test('version endpoint reports update_available when the client version differs', function () {
    $user = User::factory()->create();

    File::ensureDirectoryExists(dirname(DeployedApplicationVersion::stampPath()));
    File::put(DeployedApplicationVersion::stampPath(), 'deploy-new');

    $this->actingAs($user)
        ->getJson(route('app.version', ['client_version' => 'deploy-old']))
        ->assertOk()
        ->assertJsonPath('version', 'deploy-new')
        ->assertJsonPath('update_available', true);

    File::delete(DeployedApplicationVersion::stampPath());
});

test('deployed version is stable and does not expose environment secrets', function () {
    File::ensureDirectoryExists(dirname(DeployedApplicationVersion::stampPath()));
    File::put(DeployedApplicationVersion::stampPath(), 'release-stable');

    $first = DeployedApplicationVersion::current();
    $second = DeployedApplicationVersion::current();

    expect($first)->toBe('release-stable')
        ->and($second)->toBe($first)
        ->and($first)->not->toContain((string) config('app.key'));

    File::delete(DeployedApplicationVersion::stampPath());
});

test('inertia shares app_refresh version props for authenticated users', function () {
    $user = User::factory()->create();
    $company = createAppRefreshCompany('Shared');

    $user->companies()->syncWithoutDetaching([
        $company->id => ['status' => 'active'],
    ]);

    File::ensureDirectoryExists(dirname(DeployedApplicationVersion::stampPath()));
    File::put(DeployedApplicationVersion::stampPath(), 'shared-version');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('app_refresh.version', 'shared-version')
            ->where(
                'app_refresh.authorization_revision',
                AuthorizationRevision::current($user->fresh(), $company->id),
            )
        );

    File::delete(DeployedApplicationVersion::stampPath());
});
