<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{company: Company, user: User, employee: Employee, rank: Rank, vessel: Vessel}
 */
function makeCrewOperationsFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CO'.fake()->unique()->numerify('##'),
        'name' => 'Crew Ops Land',
        'dial_code' => '+002',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CO'.fake()->unique()->numerify('##'),
        'name' => 'Crew Ops Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Operations Co',
        'slug' => 'crew-ops-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
    ]);

    $user->update(['current_company_id' => $company->id]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => null,
        'name' => 'Crew Ops Dept',
    ]);

    $rank = Rank::query()->create([
        'name' => 'CO Rank '.Str::uuid()->toString(),
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'rank_id' => $rank->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);

    $vesselType = VesselType::query()->create([
        'name' => 'CO Vessel Type '.Str::uuid()->toString(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'CO Vessel '.Str::uuid()->toString(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    return compact('company', 'user', 'employee', 'rank', 'vessel');
}
