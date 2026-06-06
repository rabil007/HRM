<?php

use App\Models\Employee;
use App\Models\HikvisionPerson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function fakeHikvisionPersonCrudApi(string $personId = 'new-person-1'): void
{
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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/add' => Http::response([
            'data' => [
                'personId' => $personId,
                'personCode' => 'EMP999',
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/get' => Http::response([
            'data' => [
                'personInfo' => [
                    'personId' => $personId,
                    'firstName' => 'New',
                    'lastName' => 'Person',
                    'personCode' => 'EMP999',
                    'groupId' => 'group-1',
                    'email' => 'new@example.com',
                    'phone' => '+971500000099',
                    'gender' => 2,
                    'startDate' => '2022-02-21T20:12:45+08:00',
                    'endDate' => '2032-02-21T20:12:45+08:00',
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/update' => Http::response([
            'data' => [],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/delete' => Http::response([
            'data' => [],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/photo' => Http::response([
            'data' => [],
            'errorCode' => '0',
        ], 200),
    ]);
}

test('user with permission can create hikvision person', function () {
    configuredHikvisionSettings();
    fakeHikvisionPersonCrudApi();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.create']);

    $this->actingAs($user)
        ->from(route('hikvision.persons.index'))
        ->post(route('hikvision.persons.store'), [
            'first_name' => 'New',
            'last_name' => 'Person',
            'person_code' => 'EMP999',
            'email' => 'new@example.com',
            'phone' => '+971500000099',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionPerson::query()->where('person_id', 'new-person-1')->exists())->toBeTrue();
});

test('user with permission can update hikvision person', function () {
    configuredHikvisionSettings();
    fakeHikvisionPersonCrudApi('person-update-1');

    $person = HikvisionPerson::query()->create([
        'person_id' => 'person-update-1',
        'full_name' => 'Old Name',
        'first_name' => 'Old',
        'last_name' => 'Name',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.update']);

    $this->actingAs($user)
        ->from(route('hikvision.persons.index'))
        ->put(route('hikvision.persons.update', $person), [
            'first_name' => 'Updated',
            'last_name' => 'Person',
            'person_code' => 'EMP999',
            'group_id' => 'group-1',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/hccgw/person/v1/persons/update')) {
            return false;
        }

        $body = $request->data();

        return ($body['personId'] ?? null) === 'person-update-1'
            && ($body['groupId'] ?? null) === 'group-1'
            && ($body['firstName'] ?? null) === 'Updated'
            && ($body['lastName'] ?? null) === 'Person'
            && ! array_key_exists('personInfo', $body);
    });

    expect($person->fresh()->full_name)->toBe('Updated Person');
});

test('user with permission can delete hikvision person and clear employee link', function () {
    configuredHikvisionSettings();
    fakeHikvisionPersonCrudApi('person-delete-1');

    $person = HikvisionPerson::query()->create([
        'person_id' => 'person-delete-1',
        'full_name' => 'Delete Me',
    ]);

    $employee = Employee::factory()->create([
        'hikvision_person_id' => $person->id,
    ]);

    $user = User::factory()->create();
    grantCompanyPermissions($user, $employee->company, ['hikvision.persons.delete']);

    $this->actingAs($user)
        ->from(route('hikvision.persons.index'))
        ->delete(route('hikvision.persons.destroy', $person))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionPerson::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and($employee->fresh()->hikvision_person_id)->toBeNull();
});

test('user without create permission cannot store hikvision person', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->post(route('hikvision.persons.store'), [
            'first_name' => 'Blocked',
        ])
        ->assertForbidden();
});

test('user with permission can upload hikvision person photo', function () {
    configuredHikvisionSettings();
    fakeHikvisionPersonCrudApi('person-photo-1');

    $person = HikvisionPerson::query()->create([
        'person_id' => 'person-photo-1',
        'full_name' => 'Photo User',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.update']);

    $this->actingAs($user)
        ->from(route('hikvision.persons.index'))
        ->post(route('hikvision.persons.photo', $person), [
            'photo' => UploadedFile::fake()->image('person.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');
});
