<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;
use App\Support\Payroll\CrewTimeline\CrewTimelineDayAllocator;
use App\Support\Payroll\CrewTimeline\CrewTimelineSourceHasher;
use Carbon\Carbon;

test('p0 is excluded and p2a generates sign-on standby in pay category resolver', function () {
    $resolver = new CrewPhasePayCategoryResolver;

    expect($resolver->resolve(CrewPhaseCode::PreMobilisation))->toBe(CrewTimesheetPayCategory::Excluded)
        ->and($resolver->resolve(CrewPhaseCode::TravelIn))->toBe(CrewTimesheetPayCategory::Excluded)
        ->and($resolver->resolve(CrewPhaseCode::JoinStandby))->toBe(CrewTimesheetPayCategory::SignOnStandby);
});

test('exact p0 to p2a same day boundary allocates sign on standby without double counting or gaps', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 120,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-04-05 08:00:00',
        'planned_arrival_at' => '2026-04-10 10:00:00',
        'planned_join_at' => '2026-04-12',
    ]);

    // Phase 1: P0 from 2026-04-05 08:00 to 2026-04-10 14:00 (Completed)
    $p0 = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-04-05 08:00:00',
        'actual_end_at' => '2026-04-10 14:00:00',
    ]);

    // Phase 2: P2A starting at 2026-04-10 14:00 through 2026-04-15 10:00
    $p2a = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-04-10 14:00:00',
        'actual_end_at' => null,
    ]);

    $assignment->update(['current_phase_id' => $p2a->id]);

    $phases = collect([$p0->load('assignment'), $p2a->load('assignment')]);
    $effectiveEnd = Carbon::parse('2026-04-15', $company->timezone);

    $allocator = app(CrewTimelineDayAllocator::class);
    $allocated = collect($allocator->allocate($period, $phases, $effectiveEnd, $company->id));

    // Filter to employee
    $employeeDays = $allocated->where('employee_id', $employee->id);

    // On 2026-04-10 (the transition day), P2A has higher precedence than P0 (SignOnStandby wins over Excluded)
    $transitionDay = $employeeDays->firstWhere('date', '2026-04-10');
    expect($transitionDay)->not->toBeNull()
        ->and($transitionDay['pay_category'])->toBe(CrewTimesheetPayCategory::SignOnStandby)
        ->and($transitionDay['phase_code'])->toBe(CrewPhaseCode::JoinStandby);

    // Days before 2026-04-10 were P0 (Excluded)
    $preMobDay = $employeeDays->firstWhere('date', '2026-04-09');
    expect($preMobDay)->not->toBeNull()
        ->and($preMobDay['pay_category'])->toBe(CrewTimesheetPayCategory::Excluded);

    // Exactly one allocation per calendar day
    $dateCounts = $employeeDays->groupBy('date')->map->count();
    expect($dateCounts->every(fn (int $count) => $count === 1))->toBeTrue();
});

test('updating planned_arrival_at does not alter the crew timeline source hash', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 120,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);

    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-04-05 08:00:00',
        'planned_arrival_at' => '2026-04-10 10:00:00',
        'planned_join_at' => '2026-04-12',
    ]);

    $p0 = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-04-05 08:00:00',
    ]);

    $assignment->update(['current_phase_id' => $p0->id]);

    $hasher = app(CrewTimelineSourceHasher::class);
    $phases = collect([$p0->fresh()->load('assignment')]);

    $hashBefore = $hasher->hash($period, null, $phases);

    // Update planned_arrival_at
    $assignment->update([
        'planned_arrival_at' => '2026-04-08 15:00:00',
    ]);

    $hashAfter = $hasher->hash($period, null, $phases);

    expect($hashBefore)->toBe($hashAfter);
});
