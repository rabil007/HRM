<?php

use App\Models\HikvisionPerson;
use App\Models\HikvisionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

function postHikvisionPersonsSync(User $user): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.persons.index'));

    return test()->actingAs($user)->post(route('hikvision.persons.sync'), [
        '_token' => csrf_token(),
    ]);
}

test('user with permission can view hikvision persons page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons')
            ->has('pagination')
            ->has('can'),
        );
});

test('user without permission cannot view hikvision persons page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertForbidden();
});

test('sync upserts persons from hikvision api', function () {
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
        'isgp.hikcentralconnect.com/api/hccgw/vims/v1/person/search' => Http::response([
            'data' => [
                'totalNum' => 2,
                'pageNum' => 1,
                'pageSize' => 50,
                'personList' => [
                    [
                        'personId' => 'person-1',
                        'firstName' => 'John',
                        'lastName' => 'Doe',
                        'phone' => '+1234567890',
                        'email' => 'john@example.com',
                        'isExpired' => 0,
                    ],
                    [
                        'personId' => 'person-2',
                        'firstName' => 'Jane',
                        'lastName' => 'Smith',
                        'phone' => '+0987654321',
                        'email' => 'jane@example.com',
                        'isExpired' => 1,
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionPerson::query()->count())->toBe(2)
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->value('first_name'))->toBe('John')
        ->and(HikvisionPerson::query()->where('person_id', 'person-2')->value('is_expired'))->toBeTrue()
        ->and(HikvisionSetting::current()->persons_last_synced_at)->not->toBeNull();
});

test('sync fails when hikvision is not configured', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)
        ->assertRedirect()
        ->assertSessionHasErrors('sync');
});

test('user without sync permission cannot sync hikvision persons', function () {
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    test()->actingAs($user)->get(route('hikvision.persons.index'));

    test()->actingAs($user)
        ->post(route('hikvision.persons.sync'), ['_token' => csrf_token()])
        ->assertForbidden();
});
