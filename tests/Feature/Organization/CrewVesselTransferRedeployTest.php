<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\Reports\CrewMovementHistoryFilters;
use App\Support\Reports\CrewMovementHistoryQuery;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

function transferRedeployService(): CrewMovementService
{
    return app(CrewMovementService::class);
}

/**
 * @return array{0: CrewAssignment, 1: array{company: mixed, employee: mixed, rank: mixed, user: mixed}, 2: Vessel}
 */
function makeOnVesselSourceAssignment(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 90]);
    $vessel = makeCrewMovementVessel('Transfer Source '.uniqid(), $company);
    $service = transferRedeployService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-07-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-07-01 12:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-07-01 16:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $user->id);

    return [$assignment, $fixtures, $vessel];
}

test('direct vessel transfer closes source p4 and starts destination in active p4', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('Transfer Destination '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $source->refresh()->load(['phases', 'currentPhase']);

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($source->vessel_id)->toBe($sourceVessel->id)
        ->and($source->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($source->currentPhase?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($source->phases()->pluck('phase_code')->all())->not->toContain(CrewPhaseCode::DemobStandby)
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->source)->toBe('vessel_transfer')
        ->and($destination->status)->toBe(CrewAssignmentStatus::Active)
        ->and($destination->planned_join_at)->toBeNull()
        ->and($destination->vessel_id)->toBe($destinationVessel->id)
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($destination->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($destination->phases)->toHaveCount(1)
        ->and($destination->phases->first()?->actual_start_at?->toDateTimeString())->toBe(
            $source->currentPhase?->actual_end_at?->toDateTimeString(),
        );

    expect(EmployeeSeaService::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('crew_assignment_phase_id', $source->current_phase_id)
        ->exists())->toBeTrue();

    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $source->id)->exists())->toBeTrue()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $destination->id)->exists())->toBeTrue()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $source->id)->count())->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $destination->id)->count())->toBe(1);

    expect(Activity::query()
        ->where('company_id', $company->id)
        ->where('properties->event', 'crew_vessel_transferred')
        ->where('properties->source_assignment_id', $source->id)
        ->where('properties->destination_assignment_id', $destination->id)
        ->exists())->toBeTrue();
});

test('destination vessel must differ for transfer', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;

    expect(fn () => transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $sourceVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'Destination vessel must differ');
});

test('exact timestamp handoff is not a blocking overlap for transfer', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('Handoff Destination '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $source->refresh()->load('currentPhase');

    expect($source->currentPhase?->actual_end_at?->equalTo($destination->currentPhase?->actual_start_at))->toBeTrue()
        ->and(CrewAssignment::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $source->employee_id)
            ->where('status', CrewAssignmentStatus::Active)
            ->count())->toBe(1);
});

test('crew assignments shows only the latest active assignment after transfer', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('Crew Assignments Dest '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $page = CurrentCrewQuery::paginate($company->id);
    $ids = collect($page->items())->pluck('id')->all();

    expect($ids)->toContain($destination->id)
        ->and($ids)->not->toContain($source->id);
});

test('movement history preserves both linked assignments after transfer', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('History Dest '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $history = (new CrewMovementHistoryQuery(
        $company->id,
        new CrewMovementHistoryFilters,
        (string) $company->timezone,
    ))->paginate(50);

    $ids = collect($history->items())->pluck('id')->all();

    expect($ids)->toContain($source->id)
        ->and($ids)->toContain($destination->id);
});

test('failed transfer rolls back without leaving a partial destination assignment', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $beforeCount = CrewAssignment::query()->where('company_id', $company->id)->count();

    expect(fn () => transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => 999999,
            'rank_id' => $rank->id,
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class);

    $source->refresh();

    expect($source->status)->toBe(CrewAssignmentStatus::Active)
        ->and($source->vessel_id)->toBe($sourceVessel->id)
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe($beforeCount);
});

test('redeploy from p5 can start at chosen phases including same vessel', function (string $startingPhase, CrewAssignmentStatus $expectedStatus, CrewPhaseCode $expectedPhase) {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $service = transferRedeployService();

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $payload = [
        'occurred_at' => '2026-07-15 09:00:00',
        'starting_phase' => $startingPhase,
        'vessel_id' => $sourceVessel->id,
        'rank_id' => $rank->id,
    ];

    $destination = $service->perform(
        $company->id,
        $source->id,
        CrewMovementAction::Redeploy,
        $payload,
        $user->id,
    );

    $source->refresh();

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->source)->toBe('redeployment')
        ->and($destination->planned_join_at)->toBeNull()
        ->and($destination->status)->toBe($expectedStatus)
        ->and($destination->currentPhase?->phase_code)->toBe($expectedPhase)
        ->and($destination->phases)->toHaveCount(1)
        ->and($destination->vessel_id)->toBe($sourceVessel->id);

    expect(Activity::query()
        ->where('properties->event', 'crew_redeployed')
        ->where('properties->destination_assignment_id', $destination->id)
        ->exists())->toBeTrue();
})->with([
    'p0' => ['p0', CrewAssignmentStatus::Draft, CrewPhaseCode::PreMobilisation],
    'p1' => ['p1', CrewAssignmentStatus::Active, CrewPhaseCode::TravelIn],
    'p2a' => ['p2a', CrewAssignmentStatus::Active, CrewPhaseCode::JoinStandby],
    'p3' => ['p3', CrewAssignmentStatus::Active, CrewPhaseCode::ReadyToJoin],
    'p4' => ['p4', CrewAssignmentStatus::Active, CrewPhaseCode::OnVessel],
]);

test('redeploy from p6 is available and completes the source assignment', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $service = transferRedeployService();

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    expect(CrewMovementAvailableActions::for($source->fresh(['currentPhase'])))
        ->toContain(CrewMovementAction::Redeploy->value);

    $destination = $service->perform($company->id, $source->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-07-20 10:00:00',
        'starting_phase' => 'p1',
        'vessel_id' => $sourceVessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect($source->fresh()->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);
});

test('cross-company destination vessel references are rejected on transfer', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel '.uniqid(), $otherCompany);

    expect(fn () => transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $foreignVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'The selected vessel does not belong to this company.');
});

test('cross-company destination vessel references are rejected on join', function () {
    $fixtures = makeCrewAssignmentFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Join Vessel '.uniqid(), $otherCompany);
    $service = transferRedeployService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-07-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-07-01 12:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    expect(fn () => $service->perform(
        $company->id,
        $id,
        CrewMovementAction::JoinVessel,
        [
            'occurred_at' => '2026-07-01 16:00:00',
            'vessel_id' => $foreignVessel->id,
            'rank_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'The selected vessel does not belong to this company.');

    expect(CrewAssignment::query()->findOrFail($id)->vessel_id)->toBeNull();
});

test('cross-company destination vessel references are rejected on redeploy', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Redeploy Vessel '.uniqid(), $otherCompany);
    $service = transferRedeployService();

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $beforeCount = CrewAssignment::query()->where('company_id', $company->id)->count();

    expect(fn () => $service->perform(
        $company->id,
        $source->id,
        CrewMovementAction::Redeploy,
        [
            'occurred_at' => '2026-07-15 09:00:00',
            'starting_phase' => 'p4',
            'vessel_id' => $foreignVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'The selected vessel does not belong to this company.');

    expect($source->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe($beforeCount);
});

test('redeploy to p0 clears planned sign-off and does not require destination vessel', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $service = transferRedeployService();

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $destination = $service->perform($company->id, $source->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-07-15 09:00:00',
        'starting_phase' => 'p0',
        'planned_signoff_at' => '2026-08-01',
        'vessel_id' => null,
        'rank_id' => null,
        'client_id' => null,
    ], $user->id);

    expect($destination->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($destination->vessel_id)->toBeNull()
        ->and($destination->planned_signoff_at)->toBeNull();
});

test('transfer request rejects source vessel as destination', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-assignments.show', $source))
        ->post(route('organization.crew-assignments.perform-action', $source), [
            'action' => 'transfer_vessel',
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $sourceVessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('vessel_id');
});

test('direct transfer applies destination rank tour snapshot without copying source tour', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 90]);
    $destinationRank = Rank::query()->create([
        'name' => 'Transfer Dest Rank '.uniqid(),
        'is_active' => true,
        'max_tour_of_duty_days' => 60,
    ]);
    $destinationVessel = makeCrewMovementVessel('Tour Transfer Dest '.uniqid(), $company);

    $source->refresh();
    $sourceTourDays = $source->tour_of_duty_days;
    $sourcePlannedSignoff = $source->planned_signoff_at?->toDateTimeString();
    $sourceP4Start = $source->currentPhase?->actual_start_at?->toDateTimeString();

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $destinationRank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    );

    $source->refresh()->load('currentPhase');
    $destination->refresh()->load('currentPhase');

    expect($source->vessel_id)->toBe($sourceVessel->id)
        ->and($source->rank_id)->toBe($rank->id)
        ->and($source->tour_of_duty_days)->toBe($sourceTourDays)
        ->and($source->planned_signoff_at?->toDateTimeString())->toBe($sourcePlannedSignoff)
        ->and($source->currentPhase?->actual_start_at?->toDateTimeString())->toBe($sourceP4Start)
        ->and($source->currentPhase?->actual_end_at?->toDateTimeString())->toBe('2026-07-11 12:00:00')
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->rank_id)->toBe($destinationRank->id)
        ->and($destination->tour_of_duty_days)->toBe(60)
        ->and($destination->planned_signoff_source)->toBe(CrewPlannedSignoffSource::TourOfDuty)
        ->and($destination->planned_signoff_at?->timezone($company->timezone)->toDateString())->toBe('2026-09-09')
        ->and($destination->currentPhase?->planned_end_at?->timezone($company->timezone)->toDateString())->toBe('2026-09-09')
        ->and($destination->tour_of_duty_days)->not->toBe($source->tour_of_duty_days);

    expect(Activity::query()
        ->where('company_id', $company->id)
        ->where('properties->event', 'crew_vessel_transferred')
        ->where('properties->destination_assignment_id', $destination->id)
        ->where('properties->tour_of_duty_days', 60)
        ->exists())->toBeTrue();

    expect(EmployeeSeaService::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->count())->toBe(1)
        ->and(EmployeeSeaService::query()
            ->where('crew_assignment_phase_id', $destination->current_phase_id)
            ->exists())->toBeFalse();
});

test('direct transfer applies Rank Master tour suggestion', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 45]);
    $destinationVessel = makeCrewMovementVessel('Rank Master Transfer Dest '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    );

    expect($destination->tour_of_duty_days)->toBe(45)
        ->and($destination->planned_signoff_at?->timezone($company->timezone)->toDateString())->toBe('2026-08-25');
});

test('direct transfer supports manual planned sign-off override and rolls back on invalid tour choice', function () {
    [$source, $fixtures] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 90]);
    $destinationVessel = makeCrewMovementVessel('Manual Transfer Dest '.uniqid(), $company);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2026-08-01',
            'planned_signoff_override_reason' => 'Contract ends early',
        ],
        $user->id,
    );

    expect($destination->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($destination->planned_signoff_override_reason)->toBe('Contract ends early')
        ->and($destination->planned_signoff_at?->timezone($company->timezone)->toDateString())->toBe('2026-08-01')
        ->and($destination->currentPhase?->planned_end_at?->timezone($company->timezone)->toDateString())->toBe('2026-08-01');

    [$sourceFail, $fixturesFail, $sourceVesselFail] = makeOnVesselSourceAssignment();
    ['company' => $companyFail, 'rank' => $rankFail, 'user' => $userFail] = $fixturesFail;
    $rankFail->update(['max_tour_of_duty_days' => 90]);
    $beforeCount = CrewAssignment::query()->where('company_id', $companyFail->id)->count();
    $destFail = makeCrewMovementVessel('Fail Transfer Dest '.uniqid(), $companyFail);

    expect(fn () => transferRedeployService()->perform(
        $companyFail->id,
        $sourceFail->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destFail->id,
            'rank_id' => $rankFail->id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2026-08-01',
        ],
        $userFail->id,
    ))->toThrow(ValidationException::class);

    $sourceFail->refresh();

    expect($sourceFail->status)->toBe(CrewAssignmentStatus::Active)
        ->and($sourceFail->vessel_id)->toBe($sourceVesselFail->id)
        ->and(CrewAssignment::query()->where('company_id', $companyFail->id)->count())->toBe($beforeCount);
});

test('direct p4 redeploy applies fresh tour while pre-p4 redeploy does not', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 90]);
    $service = transferRedeployService();

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $p4Destination = $service->perform($company->id, $source->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-07-15 09:00:00',
        'starting_phase' => 'p4',
        'vessel_id' => $sourceVessel->id,
        'rank_id' => $rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $user->id);

    expect($p4Destination->tour_of_duty_days)->toBe(90)
        ->and($p4Destination->planned_signoff_source)->toBe(CrewPlannedSignoffSource::TourOfDuty)
        ->and($p4Destination->currentPhase?->planned_end_at?->timezone($company->timezone)->toDateString())->toBe('2026-10-13');

    [$source2, $fixtures2, $sourceVessel2] = makeOnVesselSourceAssignment();
    ['company' => $company2, 'rank' => $rank2, 'user' => $user2] = $fixtures2;
    $rank2->update(['max_tour_of_duty_days' => 90]);
    $service->perform($company2->id, $source2->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-07-12 08:00:00',
        'next_phase' => 'p5',
    ], $user2->id);

    $preP4 = $service->perform($company2->id, $source2->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-07-15 09:00:00',
        'starting_phase' => 'p3',
        'vessel_id' => $sourceVessel2->id,
        'rank_id' => $rank2->id,
    ], $user2->id);

    expect($preP4->tour_of_duty_days)->toBeNull()
        ->and($preP4->planned_signoff_source)->toBeNull()
        ->and($preP4->currentPhase?->phase_code)->toBe(CrewPhaseCode::ReadyToJoin);

    $joined = $service->perform($company2->id, $preP4->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-07-20 10:00:00',
        'vessel_id' => $sourceVessel2->id,
        'rank_id' => $rank2->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $user2->id);

    expect($joined->tour_of_duty_days)->toBe(90)
        ->and($joined->planned_signoff_source)->toBe(CrewPlannedSignoffSource::TourOfDuty)
        ->and($joined->currentPhase?->planned_end_at?->timezone($company2->timezone)->toDateString())->toBe('2026-10-18');
});

test('transfer projected manning reflects source loss and destination gain without double count', function () {
    [$source, $fixtures, $sourceVessel] = makeOnVesselSourceAssignment();
    ['company' => $company, 'rank' => $rank, 'user' => $user] = $fixtures;
    $rank->update(['max_tour_of_duty_days' => 90]);
    $destinationVessel = makeCrewMovementVessel('Projection Transfer Dest '.uniqid(), $company);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $sourceVessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $destinationVessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    $destination = transferRedeployService()->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-07-11 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    );

    $projection = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        '2026-07-12',
        '2026-07-31',
    );

    $sourceItem = collect($projection['items'])->first(
        fn (array $item): bool => (int) $item['vessel_id'] === (int) $sourceVessel->id
            && (int) $item['rank_id'] === (int) $rank->id,
    );
    $destinationItem = collect($projection['items'])->first(
        fn (array $item): bool => (int) $item['vessel_id'] === (int) $destinationVessel->id
            && (int) $item['rank_id'] === (int) $rank->id,
    );

    $handoffDay = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        '2026-07-11',
        '2026-07-11',
    );
    $handoffSource = collect($handoffDay['items'])->first(
        fn (array $item): bool => (int) $item['vessel_id'] === (int) $sourceVessel->id,
    );
    $handoffDestination = collect($handoffDay['items'])->first(
        fn (array $item): bool => (int) $item['vessel_id'] === (int) $destinationVessel->id,
    );

    expect($sourceItem)->not->toBeNull()
        ->and($destinationItem)->not->toBeNull()
        ->and($sourceItem['actual_onboard_at_start'])->toBe(0)
        ->and($destinationItem['actual_onboard_at_start'])->toBe(1)
        ->and($destinationItem['projected_count_at_start'])->toBe(1)
        ->and($destination->planned_signoff_at)->not->toBeNull()
        ->and(($handoffSource['maximum_projected_count'] ?? 0) + ($handoffDestination['maximum_projected_count'] ?? 0))->toBeLessThanOrEqual(2)
        ->and($handoffDestination['has_overlap'] ?? false)->toBeFalse();
});
