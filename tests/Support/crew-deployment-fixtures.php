<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;

function makeCrewDeploymentVessel(string $name): Vessel
{
    $vesselType = VesselType::query()->firstOrCreate(
        ['name' => 'Crew Deployment Test Type'],
        ['is_active' => true],
    );

    return Vessel::query()->firstOrCreate(
        ['name' => $name],
        ['vessel_type_id' => $vesselType->id, 'is_active' => true],
    );
}

function makeCrewDeploymentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CRW',
        'name' => 'Crewland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRW',
        'name' => 'Crew Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Co',
        'slug' => 'crew-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rank = Rank::query()->create([
        'name' => 'SM',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '2018',
            'name' => 'Boby Jahja',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.deployments.view',
        'crew_operations.deployments.create',
        'crew_operations.deployments.update',
        'crew_operations.deployments.delete',
        'crew_operations.deployments.export',
    ]);

    return compact('user', 'company', 'employee', 'rank');
}
