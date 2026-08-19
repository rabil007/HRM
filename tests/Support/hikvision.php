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

    $settings = HikvisionSetting::resolveForUpdate($companyId);

    $settings->storeFromValidated([
        'api_host' => 'https://isgp.hikcentralconnect.com',
        'api_key' => 'test-api-key',
        'api_secret' => 'test-api-secret',
        'enabled' => true,
    ]);

    return $settings->fresh() ?? $settings;
}

function hikvisionTestCompany(): Company
{
    $company = Company::query()->first();

    if ($company !== null) {
        return $company;
    }

    return setupCompanyWithSettingsPermissions(User::factory()->create(), []);
}

function additionalHikvisionTestCompany(Company $company, string $slug = 'hikvision-other-company'): Company
{
    return Company::query()->create([
        'name' => 'Hikvision Other Company '.$slug,
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function hikvisionSettings(): HikvisionSetting
{
    $companyId = hikvisionTestCompany()->id;

    return HikvisionSetting::query()->where('company_id', $companyId)->first()
        ?? configuredHikvisionSettings($companyId);
}

function registerHikvisionModelCreatingHooks(): void
{
    foreach ([
        HikvisionAccessEvent::class,
        HikvisionDevice::class,
        HikvisionPerson::class,
        HikvisionPersonGroup::class,
    ] as $model) {
        $model::creating(function ($instance): void {
            $companyId = $instance->getAttributes()['company_id'] ?? null;

            if ($companyId === null || (int) $companyId <= 0) {
                $instance->company_id = hikvisionTestCompany()->id;
            }
        });
    }
}

pest()->beforeEach(function (): void {
    registerHikvisionModelCreatingHooks();
});

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
