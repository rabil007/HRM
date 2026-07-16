<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewAssignmentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CA'.fake()->unique()->numerify('##'),
        'name' => 'Crew Assignment Land',
        'dial_code' => '+001',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CA'.fake()->unique()->numerify('##'),
        'name' => 'Crew Assignment Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Assignment Co',
        'slug' => 'crew-assignment-'.Str::lower(Str::random(6)),
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

    $rank = Rank::query()->create([
        'name' => 'CA Rank '.Str::uuid()->toString(),
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    return compact('user', 'company', 'employee', 'rank');
}

function makeCrewMovementVessel(string $name): Vessel
{
    return Vessel::query()->create([
        'name' => $name.' '.Str::uuid()->toString(),
        'vessel_type_id' => VesselType::query()->create([
            'name' => 'CM VT '.Str::uuid()->toString(),
            'is_active' => true,
        ])->id,
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeActiveOnVesselAssignment(
    Company $company,
    Employee $employee,
    Rank $rank,
    Vessel $vessel,
    array $overrides = [],
): CrewAssignment {
    $started = CarbonImmutable::parse('2026-01-01 08:00:00', $company->timezone ?? 'UTC');

    $assignment = CrewAssignment::query()->create(array_merge([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-'.Str::upper(Str::random(6)),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $started,
        'source' => 'manual',
    ], $overrides));

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $started->addDays(2),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase', 'vessel', 'employee']);
}
