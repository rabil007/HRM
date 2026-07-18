<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionDevice;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use App\Models\User;

function configuredHikvisionSettings(?int $companyId = null): HikvisionSetting
{
    $companyId ??= hikvisionTestCompany()->id;

    if ($companyId <= 0) {
        throw new InvalidArgumentException('configuredHikvisionSettings requires a company. Call setupCompanyWithSettingsPermissions first or pass companyId.');
    }

    $settings = HikvisionSetting::forCompany($companyId);

    $settings->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ]);

    return $settings;
}

function hikvisionTestCompany(): Company
{
    $company = Company::query()->first();

    if ($company !== null) {
        return $company;
    }

    return setupCompanyWithSettingsPermissions(User::factory()->create(), []);
}

function hikvisionSettings(): HikvisionSetting
{
    return HikvisionSetting::forCompany(hikvisionTestCompany()->id);
}

foreach ([
    HikvisionAccessEvent::class,
    HikvisionDevice::class,
    HikvisionPerson::class,
    HikvisionPersonGroup::class,
] as $model) {
    $model::creating(function ($model): void {
        if ($model->company_id === null) {
            $model->company_id = hikvisionTestCompany()->id;
        }
    });
}

function linkHikvisionPersonToUserCompany(
    Employee $employee,
    string $personHikvisionId,
    array $personAttributes = [],
): HikvisionPerson {
    $person = HikvisionPerson::query()->create(array_merge([
        'company_id' => $employee->company_id,
        'person_id' => $personHikvisionId,
        'full_name' => $employee->name,
    ], $personAttributes));

    $employee->update(['hikvision_person_id' => $person->id]);

    return $person;
}
