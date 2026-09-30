<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\Position;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Positions\CrewPositionCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('available actions for draft pre-mobilisation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
    ], $user->id)->load('currentPhase');

    expect(CrewMovementAvailableActions::for($assignment))->toBe([
        CrewMovementAction::ApproveMobilisation->value,
        CrewMovementAction::CancelAssignment->value,
    ]);
});

test('available actions for on-vessel exclude cancel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect(CrewMovementAvailableActions::for($assignment))
        ->toContain(CrewMovementAction::PlanSignoff->value)
        ->toContain(CrewMovementAction::ConfirmDisembarkation->value)
        ->toContain(CrewMovementAction::TransferVessel->value)
        ->not->toContain(CrewMovementAction::CancelAssignment->value);
});

test('presenter separates planned and actual dates', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Dates Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_join_at' => '2026-01-01',
        'planned_signoff_at' => '2026-06-01',
    ])->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'planningAssignment']);

    $detail = CrewAssignmentPresenter::detail($assignment);
    $onVessel = collect($detail['phase_timeline'])->firstWhere('phase_code', CrewPhaseCode::OnVessel->value);

    expect($detail['planned_join_at'])->toBe('2026-01-01')
        ->and($detail['planned_signoff_at'])->toBe('2026-06-01')
        ->and($detail['actual_join_at'])->toBe($onVessel['actual_start_at'])
        ->and($detail['actual_disembarkation_at'])->toBeNull()
        ->and($detail['actual_join_at'])->not->toBe($detail['planned_signoff_at'])
        ->and($detail['current_phase']['code'])->toBe(CrewPhaseCode::OnVessel->value)
        ->and($detail['phase_timeline'])->not->toBeEmpty()
        ->and($detail['available_actions'])->toBeArray()
        ->and($detail['warnings'])->toBeArray()
        ->and($detail['mobilisation_readiness'])->toBeNull()
        ->and($detail['recommended_action'])->not->toBeNull();
});

test('list presenter includes warnings payload shape', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
    ], $user->id)->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);

    $assignment->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $item = CrewAssignmentPresenter::listItem($assignment->fresh([
        'employee',
        'rank',
        'vessel',
        'client',
        'currentPhase',
        'company',
    ]));

    expect($item['warnings'])->toBeArray()
        ->and($item['available_actions'])->toBe([
            CrewMovementAction::ApproveMobilisation->value,
            CrewMovementAction::CancelAssignment->value,
        ])
        ->and($item['mobilisation_readiness'])->toBeArray()
        ->and($item['mobilisation_readiness']['applies'])->toBeTrue()
        ->and($item['recommended_action'])->toBeArray()
        ->and($item['recommended_action']['type'])->toBe('movement')
        ->and($item['recommended_action']['action'])->toBe(CrewMovementAction::ApproveMobilisation->value);

    if ($item['warnings'] !== []) {
        expect($item['warnings'][0])->toHaveKeys(['code', 'severity', 'label', 'message', 'date']);
    }
});

test('presenter includes employee image in list and detail payloads', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $employee->update(['image' => 'employees/1/images/avatar.jpg']);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
    ], $user->id)->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company', 'phases', 'planningAssignment']);

    $listItem = CrewAssignmentPresenter::listItem($assignment);
    expect($listItem['employee'])->toBeArray()
        ->and($listItem['employee']['image'])->toBe('employees/1/images/avatar.jpg');

    $detail = CrewAssignmentPresenter::detail($assignment);
    expect($detail['employee'])->toBeArray()
        ->and($detail['employee']['image'])->toBe('employees/1/images/avatar.jpg');
});

test('presenter includes employee training id when relation is eager loaded', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Training Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel)
        ->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases.employeeTraining', 'company', 'planningAssignment']);

    $phase = $assignment->phases->first();
    $training = EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create(['source_crew_assignment_phase_id' => $phase->id]);

    $assignment->load('phases.employeeTraining');

    $detail = CrewAssignmentPresenter::detail($assignment);
    $timelinePhase = collect($detail['phase_timeline'])->firstWhere('id', $phase->id);

    expect($timelinePhase['employee_training_id'])->toBe($training->id);
});

test('presenter does not lazy-load Position and returns null when unloaded', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $position->id,
        'position_id' => $rank->id,
    ], $user->id);

    $assignment = $assignment->fresh(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);
    expect($assignment->relationLoaded('position'))->toBeFalse()
        ->and((int) $assignment->position_id)->toBe((int) $position->id);

    Model::preventLazyLoading();

    try {
        $item = CrewAssignmentPresenter::listItem($assignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($item['position'])->toBeNull();
});

test('presenter uses loaded Position and never exposes Rank id as Position id', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $position->id,
        'position_id' => $rank->id,
    ], $user->id)->load(['employee', 'position', 'rank', 'vessel', 'client', 'currentPhase', 'company']);

    Model::preventLazyLoading();

    try {
        $item = CrewAssignmentPresenter::listItem($assignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($item['position'])->toMatchArray([
        'id' => (int) $position->id,
        'name' => (string) $position->title,
    ]);

    // Guard against Rank ID misuse when primary keys differ across tables.
    $extraRank = Position::query()->create([
        'company_id' => $company->id,
        'company_id' => $company->id,
        'title' => 'ID Guard Rank '.Str::uuid()->toString(),
        'status' => 'active', 'is_crew_position' => true,
    ]);
    expect((int) $position->id)->not->toBe((int) $extraRank->id)
        ->and($item['position']['id'])->not->toBe((int) $extraRank->id);
});

test('presenter presents mapped Position for legacy Rank-only assignment after hydration', function () {
    // Skew Rank PK ahead of Position so Rank ID ≠ Position ID for misuse detection.
    Position::query()->create([
        'company_id' => $company->id,
        'company_id' => $company->id,
        'title' => 'Skew Rank '.Str::uuid()->toString(),
        'status' => 'active', 'is_crew_position' => true,
    ]);

    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position, 'user' => $user] = makeCrewAssignmentFixtures();

    expect((int) $rank->id)->not->toBe((int) $position->id);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
    ], $user->id);

    $assignment->forceFill(['position_id' => null])->saveQuietly();

    $assignment = $assignment->fresh(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);
    expect($assignment->position_id)->toBeNull()
        ->and($assignment->relationLoaded('position'))->toBeFalse();

    CrewPositionCatalog::hydrateCanonicalPositions(collect([$assignment]), (int) $company->id);

    Model::preventLazyLoading();

    try {
        $item = CrewAssignmentPresenter::listItem($assignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($item['position'])->toMatchArray([
        'id' => (int) $position->id,
        'name' => (string) $position->title,
    ])
        ->and($item['position']['id'])->not->toBe((int) $rank->id);
});

test('relieves context exposes source_position from hydrated source without Rank id misuse', function () {
    Position::query()->create([
        'company_id' => $company->id,
        'company_id' => $company->id,
        'title' => 'Skew Rank '.Str::uuid()->toString(),
        'status' => 'active', 'is_crew_position' => true,
    ]);

    ['company' => $company, 'employee' => $sourceEmployee, 'rank' => $rank, 'position' => $position, 'user' => $user] = makeCrewAssignmentFixtures();
    expect((int) $rank->id)->not->toBe((int) $position->id);
    $vessel = makeCrewMovementVessel('Presenter Relief Vessel', $company);

    $source = makeActiveOnVesselAssignment($company, $sourceEmployee, $rank, $vessel, [
        'planned_signoff_at' => now()->addDays(20)->toDateString(),
    ]);
    $source->forceFill(['position_id' => null])->saveQuietly();

    $reliefEmployee = Employee::factory()->forCompany($company)->create([
        'position_id' => $rank->id,
        'position_id' => $position->id,
        'status' => 'active',
    ]);

    $reliefAssignment = app(CrewMovementService::class)->createDraft($company->id, $reliefEmployee->id, [
        'position_id' => $position->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'position_id' => $position->id,
        'employee_id' => $reliefEmployee->id,
        'crew_assignment_id' => $reliefAssignment->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
    ]);

    $reliefAssignment = $reliefAssignment->fresh([
        'employee',
        'position',
        'rank',
        'vessel',
        'client',
        'currentPhase',
        'company',
        'phases',
        'planningAssignment.relievedAssignment.employee',
        'planningAssignment.relievedAssignment.vessel',
        'planningAssignment.relievedAssignment.rank',
        'previousAssignment',
        'nextAssignments',
    ]);

    expect($planning->id)->toBe($reliefAssignment->planningAssignment->id);

    CrewPositionCatalog::hydrateCanonicalPositions(
        collect([$reliefAssignment->planningAssignment->relievedAssignment]),
        (int) $company->id,
    );

    Model::preventLazyLoading();

    try {
        $detail = CrewAssignmentPresenter::detail($reliefAssignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($detail['relieves'])->not->toBeNull()
        ->and($detail['relieves'])->not->toHaveKey('source_rank')
        ->and($detail['relieves']['source_position'])->toMatchArray([
            'id' => (int) $position->id,
            'name' => (string) $position->title,
        ])
        ->and($detail['relieves']['source_position']['id'])->not->toBe((int) $rank->id)
        ->and($detail['relieves']['source_assignment_id'])->toBe((int) $source->id);
});

test('relieves context returns null source_position for unmapped legacy Rank and never queries Position', function () {
    ['company' => $company, 'employee' => $sourceEmployee, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Unmapped Relief Vessel', $company);

    $orphanRank = Position::query()->create([
        'company_id' => $company->id,
        'company_id' => $company->id,
        'title' => 'Orphan Rank '.Str::uuid()->toString(),
        'status' => 'active', 'is_crew_position' => true,
    ]);

    $source = makeActiveOnVesselAssignment($company, $sourceEmployee, $orphanRank, $vessel);
    $source->forceFill([
        'position_id' => null,
        'position_id' => $orphanRank->id,
    ])->saveQuietly();

    $reliefEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $mappedRank = Position::query()->create([
        'company_id' => $company->id,
        'company_id' => $company->id,
        'title' => 'Mapped Relief Rank '.Str::uuid()->toString(),
        'status' => 'active', 'is_crew_position' => true,
    ]);
    $mappedPosition = ensureRankMappedPosition($company, $mappedRank);

    $reliefAssignment = app(CrewMovementService::class)->createDraft($company->id, $reliefEmployee->id, [
        'position_id' => $mappedPosition->id,
        'position_id' => $mappedRank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $mappedRank->id,
        'position_id' => $mappedPosition->id,
        'employee_id' => $reliefEmployee->id,
        'crew_assignment_id' => $reliefAssignment->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
    ]);

    $reliefAssignment = $reliefAssignment->fresh([
        'employee',
        'position',
        'rank',
        'vessel',
        'client',
        'currentPhase',
        'company',
        'phases',
        'planningAssignment.relievedAssignment.employee',
        'planningAssignment.relievedAssignment.vessel',
        'planningAssignment.relievedAssignment.rank',
        'previousAssignment',
        'nextAssignments',
    ]);

    CrewPositionCatalog::hydrateCanonicalPositions(
        collect([$reliefAssignment->planningAssignment->relievedAssignment]),
        (int) $company->id,
    );

    $positionOrMappingQueries = [];
    DB::listen(function ($query) use (&$positionOrMappingQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'positions') || str_contains($sql, 'rank_position_mappings')) {
            $positionOrMappingQueries[] = $query->sql;
        }
    });

    Model::preventLazyLoading();

    try {
        $detail = CrewAssignmentPresenter::detail($reliefAssignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($detail['relieves']['source_position'])->toBeNull()
        ->and($detail['relieves'])->not->toHaveKey('source_rank')
        ->and($positionOrMappingQueries)->toBe([]);
});

test('relieves context ignores cross-company Position mapping for source assignment', function () {
    $fixturesA = makeCrewAssignmentFixtures();
    $fixturesB = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Cross Company Vessel', $fixturesA['company']);

    $source = makeActiveOnVesselAssignment(
        $fixturesA['company'],
        $fixturesA['employee'],
        $fixturesA['rank'],
        $vessel,
    );
    // Simulate legacy Rank-only row that only has a mapping in another company.
    $source->forceFill([
        'position_id' => null,
        'position_id' => $fixturesA['rank']->id,
    ])->saveQuietly();

    // Rank mappings removed in Phase 3B
    // CrewPositionCatalog has no cache after Rank removal

    $reliefEmployee = Employee::factory()->forCompany($fixturesA['company'])->create(['status' => 'active']);
    $reliefAssignment = app(CrewMovementService::class)->createDraft(
        $fixturesA['company']->id,
        $reliefEmployee->id,
        [
            'position_id' => $fixturesA['position']->id,
            'position_id' => $fixturesA['rank']->id,
            'vessel_id' => $vessel->id,
        ],
        $fixturesA['user']->id,
    );

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixturesA['company']->id,
        'vessel_id' => $vessel->id,
        'position_id' => $fixturesA['rank']->id,
        'position_id' => $fixturesA['position']->id,
        'employee_id' => $reliefEmployee->id,
        'crew_assignment_id' => $reliefAssignment->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
    ]);

    $reliefAssignment = $reliefAssignment->fresh([
        'employee',
        'position',
        'rank',
        'vessel',
        'client',
        'currentPhase',
        'company',
        'phases',
        'planningAssignment.relievedAssignment.employee',
        'planningAssignment.relievedAssignment.vessel',
        'planningAssignment.relievedAssignment.rank',
        'previousAssignment',
        'nextAssignments',
    ]);

    CrewPositionCatalog::hydrateCanonicalPositions(
        collect([$reliefAssignment->planningAssignment->relievedAssignment]),
        (int) $fixturesA['company']->id,
    );

    Model::preventLazyLoading();

    try {
        $detail = CrewAssignmentPresenter::detail($reliefAssignment);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($detail['relieves']['source_position'])->toBeNull();
});
