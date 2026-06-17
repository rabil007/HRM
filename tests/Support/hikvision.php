<?php

use App\Models\Employee;
use App\Models\HikvisionPerson;
use App\Models\HikvisionSetting;

function configuredHikvisionSettings(): void
{
    HikvisionSetting::current()->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ]);
}

function linkHikvisionPersonToUserCompany(
    Employee $employee,
    string $personHikvisionId,
    array $personAttributes = [],
): HikvisionPerson {
    $person = HikvisionPerson::query()->create(array_merge([
        'person_id' => $personHikvisionId,
        'full_name' => $employee->name,
    ], $personAttributes));

    $employee->update(['hikvision_person_id' => $person->id]);

    return $person;
}
