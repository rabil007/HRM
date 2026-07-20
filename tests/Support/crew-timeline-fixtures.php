<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Carbon\CarbonImmutable;

function makeDailyCrewTimelineFixtures(): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);
    $employee->refresh();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $vessel = makeCrewMovementVessel('Timeline Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    return compact('user', 'company', 'employee', 'rank', 'period', 'assignment', 'vessel');
}

function addTimelinePhase(
    CrewAssignment $assignment,
    CrewPhaseCode $code,
    int $sequence,
    string $start,
    ?string $end,
    CrewPhaseStatus $status = CrewPhaseStatus::Completed,
): CrewAssignmentPhase {
    return CrewAssignmentPhase::query()->create([
        'company_id' => $assignment->company_id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => $code,
        'sequence' => $sequence,
        'status' => $status,
        'actual_start_at' => CarbonImmutable::parse($start, 'Asia/Dubai'),
        'actual_end_at' => $end !== null ? CarbonImmutable::parse($end, 'Asia/Dubai') : null,
    ]);
}
