<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

function postHikvisionPersonsSync(User $user): TestResponse
{
    test()->actingAs($user)->get(route('hikvision.persons.index'));

    return test()->actingAs($user)->post(route('hikvision.persons.sync'), [
        '_token' => csrf_token(),
    ]);
}

function fakeHikvisionPersonsApi(): void
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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => [
                'personGroupList' => [
                    [
                        'groupId' => 'group-1',
                        'groupName' => 'Engineering',
                        'parentId' => '',
                    ],
                    [
                        'groupId' => 'group-2',
                        'groupName' => 'Operations',
                        'parentId' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-1',
                            'groupId' => 'group-1',
                            'firstName' => 'Ahmed',
                            'lastName' => 'Ali',
                            'personCode' => 'EMP001',
                            'email' => 'ahmed@example.com',
                            'phone' => '+971500000001',
                        ],
                        'fingerList' => [
                            ['fingerIndex' => 1],
                        ],
                        'pinCode' => '1234',
                    ],
                    [
                        'personInfo' => [
                            'personId' => 'person-2',
                            'groupId' => 'group-2',
                            'firstName' => 'Sara',
                            'lastName' => 'Khan',
                            'personCode' => 'EMP002',
                            'email' => 'sara@example.com',
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);
}

test('persons index paginates results and returns correct pagination meta', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    foreach (range(1, 21) as $index) {
        HikvisionPerson::query()->create([
            'person_id' => "paginated-person-{$index}",
            'full_name' => sprintf('Person %02d', $index),
            'synced_at' => now(),
        ]);
    }

    HikvisionPerson::query()->create([
        'person_id' => 'paginated-person-syam',
        'full_name' => 'Syam',
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['per_page' => 20]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 22)
            ->where('pagination.per_page', 20)
            ->where('pagination.current_page', 1)
            ->where('pagination.from', 1)
            ->where('pagination.to', 20)
            ->has('persons', 20),
        );

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['per_page' => 20, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', 22)
            ->where('pagination.current_page', 2)
            ->where('pagination.from', 21)
            ->where('pagination.to', 22)
            ->has('persons', 2)
            ->where('persons.1.full_name', 'Syam'),
        );
});

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
            ->has('filters')
            ->has('group_options')
            ->has('credential_options')
            ->has('can'),
        );
});

test('persons page does not expose employees linked in another company', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $viewerCompany = $user->companies()->first();

    $otherCompany = Company::query()->create([
        'name' => 'Other Persons Co',
        'slug' => 'other-persons-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $viewerCompany->country_id,
        'currency_id' => $viewerCompany->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'name' => 'Hidden Linked Employee',
            'employee_no' => 'OTH-001',
        ]);

    linkHikvisionPersonToUserCompany($otherEmployee, 'person-other-co');

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('persons', 1)
            ->where('persons.0.full_name', 'Hidden Linked Employee')
            ->where('persons.0.linked_employee', null),
        );
});

test('persons page exposes crud permissions when granted', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.create',
        'hikvision.persons.update',
        'hikvision.persons.delete',
    ]);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true),
        );
});

test('user without permission cannot view hikvision persons page', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index'))
        ->assertForbidden();
});

test('sync upserts persons and departments from hikvision api', function () {
    fakeHikvisionPersonsApi();
    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionPersonGroup::query()->count())->toBe(2)
        ->and(HikvisionPerson::query()->count())->toBe(2)
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->value('full_name'))->toBe('Ahmed Ali')
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->value('has_fingerprint'))->toBeTrue()
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->value('has_pin'))->toBeTrue()
        ->and(HikvisionPersonGroup::query()->where('group_id', 'group-1')->value('name'))->toBe('Engineering');

    expect(HikvisionSetting::current()->persons_last_synced_at)->not->toBeNull();
});

test('sync removes local persons deleted from hikvision portal', function () {
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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => [
                'personGroupList' => [
                    [
                        'groupId' => 'group-1',
                        'groupName' => 'Engineering',
                        'parentId' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-1',
                            'groupId' => 'group-1',
                            'firstName' => 'Ahmed',
                            'lastName' => 'Ali',
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'groupId' => 'group-1',
            'firstName' => 'Ahmed',
            'lastName' => 'Ali',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $removedPerson = HikvisionPerson::query()->create([
        'person_id' => 'person-removed',
        'full_name' => 'Removed User',
    ]);

    $employee = Employee::factory()->create([
        'hikvision_person_id' => $removedPerson->id,
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(HikvisionPerson::query()->count())->toBe(1)
        ->and(HikvisionPerson::query()->where('person_id', 'person-removed')->exists())->toBeFalse()
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->exists())->toBeTrue()
        ->and($employee->fresh()->hikvision_person_id)->toBeNull();
});

test('re-sync updates existing hikvision persons', function () {
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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => [
                'personGroupList' => [
                    [
                        'groupId' => 'group-1',
                        'groupName' => 'Engineering',
                        'parentId' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-1',
                            'groupId' => 'group-1',
                            'firstName' => 'Updated',
                            'lastName' => 'Name',
                            'personCode' => 'EMP001',
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
    ]);

    configuredHikvisionSettings();

    HikvisionPersonGroup::upsertFromApi([
        'groupId' => 'group-1',
        'groupName' => 'Old Department',
        'parentId' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'groupId' => 'group-1',
            'firstName' => 'Old',
            'lastName' => 'Name',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)->assertRedirect();

    expect(HikvisionPerson::query()->count())->toBe(1)
        ->and(HikvisionPerson::query()->where('person_id', 'person-1')->value('full_name'))->toBe('Updated Name')
        ->and(HikvisionPersonGroup::query()->where('group_id', 'group-1')->value('name'))->toBe('Engineering');
});

test('persons index supports search filter', function () {
    HikvisionPersonGroup::upsertFromApi([
        'groupId' => 'group-1',
        'groupName' => 'Engineering',
        'parentId' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'groupId' => 'group-1',
            'firstName' => 'Ahmed',
            'lastName' => 'Ali',
            'personCode' => 'EMP001',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-2',
            'groupId' => 'group-1',
            'firstName' => 'Sara',
            'lastName' => 'Khan',
            'personCode' => 'EMP002',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['search' => 'EMP001']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons', 1)
            ->where('persons.0.person_code', 'EMP001'),
        );
});

test('persons index supports department filter', function () {
    HikvisionPersonGroup::upsertFromApi([
        'groupId' => 'group-1',
        'groupName' => 'Engineering',
        'parentId' => '',
    ]);

    HikvisionPersonGroup::upsertFromApi([
        'groupId' => 'group-2',
        'groupName' => 'Operations',
        'parentId' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'groupId' => 'group-1',
            'firstName' => 'Ahmed',
            'lastName' => 'Ali',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-2',
            'groupId' => 'group-2',
            'firstName' => 'Sara',
            'lastName' => 'Khan',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['group' => 'group-1']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons', 1)
            ->where('persons.0.full_name', 'Ahmed Ali')
            ->where('filters.group', 'group-1'),
        );
});

test('persons index supports unassigned department filter', function () {
    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'firstName' => 'No',
            'lastName' => 'Department',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    HikvisionPersonGroup::upsertFromApi([
        'groupId' => 'group-1',
        'groupName' => 'Engineering',
        'parentId' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-2',
            'groupId' => 'group-1',
            'firstName' => 'In',
            'lastName' => 'Department',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['group' => HikvisionPersonGroup::UNASSIGNED_GROUP_VALUE]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons', 1)
            ->where('persons.0.full_name', 'No Department'),
        );
});

test('persons index supports credential filter', function () {
    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-1',
            'firstName' => 'Fingerprint',
            'lastName' => 'User',
        ],
        'fingerList' => [['fingerIndex' => 1]],
        'pinCode' => '',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-2',
            'firstName' => 'Pin',
            'lastName' => 'User',
        ],
        'fingerList' => [],
        'pinCode' => '1234',
    ]);

    HikvisionPerson::upsertFromApi([
        'personInfo' => [
            'personId' => 'person-3',
            'firstName' => 'No',
            'lastName' => 'Credentials',
        ],
        'fingerList' => [],
        'pinCode' => '',
    ]);

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['hikvision.persons.view']);

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['credential' => HikvisionPerson::CREDENTIAL_NONE]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons', 1)
            ->where('persons.0.full_name', 'No Credentials'),
        );

    $this->actingAs($user)
        ->get(route('hikvision.persons.index', ['credential' => HikvisionPerson::CREDENTIAL_FINGERPRINT]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hikvision/persons')
            ->has('persons', 1)
            ->where('persons.0.full_name', 'Fingerprint User'),
        );
});

test('sync caches person photos locally from signed urls', function () {
    Storage::fake('public');

    $longPhotoUrl = 'https://hpc-sgp-prod-s3-person-data-storage.oss-ap-southeast-1.aliyuncs.com/GDPR001/be2e21fbf43340c881fdcf8a80d224f8/705076685447887872/0/picture.data?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=LTAI5tQckMpJxMb4qoHXJySP%2F20260606%2Foss-ap-southeast-1%2Fs3%2Faws4_request&X-Amz-Date=20260606T190055Z&X-Amz-Expires=3600&X-Amz-SignedHeaders=host&X-Amz-Signature=04a9f6f4d49d6638fd69432884766d9efdad1af6f48561a7162d2ad16b263b85';
    $imageBody = file_get_contents(__DIR__.'/../../Fixtures/hikvision-person-photo.jpg');

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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => ['personGroupList' => []],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-long-url',
                            'firstName' => 'Syam',
                            'headPicUrl' => $longPhotoUrl,
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        $longPhotoUrl => Http::response($imageBody, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)->assertRedirect();

    $person = HikvisionPerson::query()->where('person_id', 'person-long-url')->first();

    expect($person)->not->toBeNull()
        ->and($person->photo_path)->toBe('hikvision/persons/person-long-url.jpg')
        ->and($person->photo_remote_key)->toBe('/GDPR001/be2e21fbf43340c881fdcf8a80d224f8/705076685447887872/0/picture.data')
        ->and($person->photo_url)->toBe('/storage/hikvision/persons/person-long-url.jpg')
        ->and(Storage::disk('public')->exists($person->photo_path))->toBeTrue();
});

test('sync skips re-downloading photo when remote object key is unchanged', function () {
    Storage::fake('public');
    Storage::disk('public')->put('hikvision/persons/person-long-url.jpg', 'cached-image');

    $remoteKey = '/GDPR001/be2e21fbf43340c881fdcf8a80d224f8/705076685447887872/0/picture.data';
    $firstPhotoUrl = 'https://hpc-sgp-prod-s3-person-data-storage.oss-ap-southeast-1.aliyuncs.com'.$remoteKey.'?sig=first';
    $secondPhotoUrl = 'https://hpc-sgp-prod-s3-person-data-storage.oss-ap-southeast-1.aliyuncs.com'.$remoteKey.'?sig=second';

    HikvisionPerson::query()->create([
        'person_id' => 'person-long-url',
        'full_name' => 'Syam',
        'photo_path' => 'hikvision/persons/person-long-url.jpg',
        'photo_remote_key' => $remoteKey,
        'synced_at' => now()->subDay(),
    ]);

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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => ['personGroupList' => []],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-long-url',
                            'firstName' => 'Syam',
                            'headPicUrl' => $secondPhotoUrl,
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        $secondPhotoUrl => Http::response('new-image', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)->assertRedirect();

    expect(Storage::disk('public')->get('hikvision/persons/person-long-url.jpg'))->toBe('cached-image');
});

test('sync replaces cached photo when remote object key changes', function () {
    Storage::fake('public');
    Storage::disk('public')->put('hikvision/persons/person-long-url.jpg', 'old-image');

    $oldPhotoUrl = 'https://hpc-sgp-prod-s3-person-data-storage.oss-ap-southeast-1.aliyuncs.com/GDPR001/old/picture.data?sig=old';
    $newPhotoUrl = 'https://hpc-sgp-prod-s3-person-data-storage.oss-ap-southeast-1.aliyuncs.com/GDPR001/new/picture.data?sig=new';

    HikvisionPerson::query()->create([
        'person_id' => 'person-long-url',
        'full_name' => 'Syam',
        'photo_path' => 'hikvision/persons/person-long-url.jpg',
        'photo_remote_key' => '/GDPR001/old/picture.data',
        'synced_at' => now()->subDay(),
    ]);

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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => ['personGroupList' => []],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-long-url',
                            'firstName' => 'Syam',
                            'headPicUrl' => $newPhotoUrl,
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
                    ],
                ],
            ],
            'errorCode' => '0',
        ], 200),
        $newPhotoUrl => Http::response('new-image', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    configuredHikvisionSettings();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'hikvision.persons.view',
        'hikvision.persons.sync',
    ]);

    postHikvisionPersonsSync($user)->assertRedirect();

    expect(Storage::disk('public')->get('hikvision/persons/person-long-url.jpg'))->toBe('new-image')
        ->and(HikvisionPerson::query()->where('person_id', 'person-long-url')->value('photo_remote_key'))
        ->toBe('/GDPR001/new/picture.data');
});

test('sync removes cached photo when hikvision clears headPicUrl', function () {
    Storage::fake('public');
    Storage::disk('public')->put('hikvision/persons/person-long-url.jpg', 'old-image');

    HikvisionPerson::query()->create([
        'person_id' => 'person-long-url',
        'full_name' => 'Syam',
        'photo_path' => 'hikvision/persons/person-long-url.jpg',
        'photo_remote_key' => '/GDPR001/old/picture.data',
        'synced_at' => now()->subDay(),
    ]);

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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => ['personGroupList' => []],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-long-url',
                            'firstName' => 'Syam',
                            'headPicUrl' => '',
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
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

    postHikvisionPersonsSync($user)->assertRedirect();

    $person = HikvisionPerson::query()->where('person_id', 'person-long-url')->first();

    expect($person?->photo_path)->toBeNull()
        ->and($person?->photo_remote_key)->toBeNull()
        ->and(Storage::disk('public')->exists('hikvision/persons/person-long-url.jpg'))->toBeFalse();
});

test('sync prunes stale persons and deletes cached photos', function () {
    Storage::fake('public');
    Storage::disk('public')->put('hikvision/persons/person-stale.jpg', 'stale-image');

    HikvisionPerson::query()->create([
        'person_id' => 'person-stale',
        'full_name' => 'Stale User',
        'photo_path' => 'hikvision/persons/person-stale.jpg',
        'photo_remote_key' => '/GDPR001/stale/picture.data',
        'synced_at' => now()->subDay(),
    ]);

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
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/groups/search' => Http::response([
            'data' => ['personGroupList' => []],
            'errorCode' => '0',
        ], 200),
        'isgp.hikcentralconnect.com/api/hccgw/person/v1/persons/list' => Http::response([
            'data' => [
                'personList' => [
                    [
                        'personInfo' => [
                            'personId' => 'person-active',
                            'firstName' => 'Active',
                        ],
                        'fingerList' => [],
                        'pinCode' => '',
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

    postHikvisionPersonsSync($user)->assertRedirect();

    expect(HikvisionPerson::query()->where('person_id', 'person-stale')->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('hikvision/persons/person-stale.jpg'))->toBeFalse();
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
