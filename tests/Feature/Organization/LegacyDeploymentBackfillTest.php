<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeDeployment;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\LegacyDeploymentBackfillService;
use Carbon\Carbon;

test('dry run creates no database rows', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(10)->toDateString(),
        'disembarked_date' => null,
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, false);

    expect($result['result'])->toBe('eligible')
        ->and(CrewAssignment::query()->count())->toBe(0)
        ->and(CrewAssignmentPhase::query()->count())->toBe(0);
});

test('commit mode creates assignment and phases and is idempotent', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'arrived_date' => Carbon::today($company->timezone)->subDays(20)->toDateString(),
        'join_standby_from' => Carbon::today($company->timezone)->subDays(15)->toDateString(),
        'join_standby_to' => Carbon::today($company->timezone)->subDays(12)->toDateString(),
        'joined_date' => Carbon::today($company->timezone)->subDays(10)->toDateString(),
        'disembarked_date' => null,
    ]);

    $service = app(LegacyDeploymentBackfillService::class);
    $first = $service->process($deployment, true);
    $second = $service->process($deployment, true);

    expect($first['result'])->toBe('created')
        ->and($first['assignment_no'])->toBe('LEGACY-'.$deployment->id)
        ->and($second['result'])->toBe('already_migrated')
        ->and(CrewAssignment::query()->where('employee_deployment_id', $deployment->id)->count())->toBe(1);

    $assignment = CrewAssignment::query()->where('employee_deployment_id', $deployment->id)->first();
    $codes = $assignment->phases()->ordered()->pluck('phase_code')->map->value->all();

    expect($codes)->toBe(['p1', 'p2a', 'p4'])
        ->and($codes)->not->toContain('p2b')
        ->and($codes)->not->toContain('p3')
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active);
});

test('future disembarkation maps to planned sign-off', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => Carbon::today($company->timezone)->addDays(20)->toDateString(),
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);
    $assignment = CrewAssignment::query()->findOrFail($result['assignment_id']);
    $p4 = $assignment->phases()->where('phase_code', CrewPhaseCode::OnVessel)->first();

    expect($assignment->planned_signoff_at)->not->toBeNull()
        ->and($p4?->planned_end_at)->not->toBeNull()
        ->and($p4?->actual_end_at)->toBeNull()
        ->and($p4?->status)->toBe(CrewPhaseStatus::Active);
});

test('past disembarkation maps to p4 actual end', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(40)->toDateString(),
        'disembarked_date' => Carbon::today($company->timezone)->subDays(10)->toDateString(),
        'leave_standby_from' => null,
        'travelled_date' => null,
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);
    $assignment = CrewAssignment::query()->findOrFail($result['assignment_id']);
    $p4 = $assignment->phases()->where('phase_code', CrewPhaseCode::OnVessel)->first();

    expect($p4?->actual_end_at)->not->toBeNull()
        ->and($p4?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($assignment->planned_signoff_at)->toBeNull();
});

test('leave standby maps to p5 and travel completes assignment', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(60)->toDateString(),
        'disembarked_date' => Carbon::today($company->timezone)->subDays(20)->toDateString(),
        'leave_standby_from' => Carbon::today($company->timezone)->subDays(19)->toDateString(),
        'leave_standby_to' => Carbon::today($company->timezone)->subDays(10)->toDateString(),
        'travelled_date' => Carbon::today($company->timezone)->subDays(8)->toDateString(),
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);
    $assignment = CrewAssignment::query()->findOrFail($result['assignment_id']);
    $codes = $assignment->phases()->ordered()->pluck('phase_code')->map->value->all();

    expect($codes)->toContain('p5')
        ->and($codes)->toContain('p6')
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->closed_at)->not->toBeNull();
});

test('invalid date ordering creates a conflict', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => Carbon::today($company->timezone)->subDays(20)->toDateString(),
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);

    expect($result['result'])->toBe('conflict')
        ->and($result['reason'])->toContain('Joined date occurs after')
        ->and(CrewAssignment::query()->count())->toBe(0);
});

test('another active assignment creates a conflict', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    $live = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [], $user->id);
    app(CrewMovementService::class)->perform(
        $company->id,
        $live->id,
        CrewMovementAction::ApproveMobilisation,
        ['occurred_at' => '2026-01-01 08:00:00'],
        $user->id,
    );

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(3)->toDateString(),
        'disembarked_date' => null,
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);

    expect($result['result'])->toBe('conflict')
        ->and($result['reason'])->toContain('already has an active crew assignment');
});

test('source deployment remains unchanged after backfill', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => Carbon::today($company->timezone)->subDays(5)->toDateString(),
        'disembarked_date' => null,
        'remarks' => 'Do not touch',
    ]);

    $before = $deployment->fresh()->toArray();
    app(LegacyDeploymentBackfillService::class)->process($deployment, true);
    $after = $deployment->fresh()->toArray();

    expect($after)->toBe($before);
});

test('p2b and p3 are never invented', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'arrived_date' => Carbon::today($company->timezone)->subDays(30)->toDateString(),
        'join_standby_from' => Carbon::today($company->timezone)->subDays(25)->toDateString(),
        'joined_date' => Carbon::today($company->timezone)->subDays(20)->toDateString(),
        'disembarked_date' => Carbon::today($company->timezone)->subDays(5)->toDateString(),
        'leave_standby_from' => Carbon::today($company->timezone)->subDays(4)->toDateString(),
        'travelled_date' => Carbon::today($company->timezone)->subDays(1)->toDateString(),
    ]);

    $result = app(LegacyDeploymentBackfillService::class)->process($deployment, true);
    $codes = collect($result['phases'])->pluck('phase_code')->all();

    expect($codes)->not->toContain('p2b')
        ->and($codes)->not->toContain('p3')
        ->and($codes)->not->toContain('p0');
});
