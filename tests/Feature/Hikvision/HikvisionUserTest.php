<?php

use App\Models\HikvisionSetting;
use App\Models\HikvisionUser;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function postHikvisionUsersSync(User $user): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.users.index'));

    return test()->actingAs($user)->post(route('hikvision.users.sync'), [
        '_token' => csrf_token(),
    ]);
}

function configuredHikvisionSettings(): void
{
    HikvisionSetting::current()->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ]);
}

test('user with permission can view hikvision users page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.users.view']);

    $this->actingAs($user)
        ->get(route('hikvision.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/users')
            ->has('users')
            ->has('pagination')
            ->has('can'),
        );
});

test('user without permission cannot view hikvision users page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $this->actingAs($user)
        ->get(route('hikvision.users.index'))
        ->assertForbidden();
});

test('sync upserts users from hikvision api', function () {
    Http::fake([
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/token/get' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/users/get' => Http::response([
            'data' => [
                'totalCount' => 2,
                'pageIndex' => 1,
                'pageSize' => 50,
                'user' => [
                    ['id' => 'hv-user-1', 'name' => 'Siraj'],
                    ['id' => 'hv-user-2', 'name' => 'Syam'],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.users.view',
        'hikvision.users.sync',
    ]);

    postHikvisionUsersSync($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionUser::query()->count())->toBe(2)
        ->and(HikvisionUser::query()->where('hikvision_id', 'hv-user-1')->value('name'))->toBe('Siraj');
});

test('re-sync updates existing hikvision users', function () {
    Http::fake([
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/token/get' => Http::response([
            'data' => [
                'accessToken' => 'hcc.test-token',
                'expireTime' => 1781256540,
                'userId' => 'user-123',
                'areaDomain' => 'https://isgp.hikcentralconnect.com',
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/platform/v1/users/get' => Http::response([
            'data' => [
                'totalCount' => 1,
                'pageIndex' => 1,
                'pageSize' => 50,
                'user' => [
                    ['id' => 'hv-user-1', 'name' => 'Updated Name'],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    HikvisionUser::upsertFromApi(['id' => 'hv-user-1', 'name' => 'Old Name']);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.users.view',
        'hikvision.users.sync',
    ]);

    postHikvisionUsersSync($user)->assertRedirect();

    expect(HikvisionUser::query()->count())->toBe(1)
        ->and(HikvisionUser::query()->where('hikvision_id', 'hv-user-1')->value('name'))->toBe('Updated Name');
});

test('sync fails when hikvision is not configured', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.users.view',
        'hikvision.users.sync',
    ]);

    postHikvisionUsersSync($user)
        ->assertRedirect()
        ->assertSessionHasErrors('sync');
});

test('user without sync permission cannot sync hikvision users', function () {
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.users.view']);

    test()->actingAs($user)->get(route('hikvision.users.index'));

    test()->actingAs($user)
        ->post(route('hikvision.users.sync'), ['_token' => csrf_token()])
        ->assertForbidden();
});
