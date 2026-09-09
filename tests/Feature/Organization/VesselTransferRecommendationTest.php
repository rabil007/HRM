<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\ActiveOnVesselAssignmentFinder;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Str;

test('employee active on vessel is detected as a possible transfer target', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    $current = app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    );

    expect($current)->not->toBeNull()
        ->and($current['assignment_id'])->toBe($assignment->id)
        ->and($current['vessel_id'])->toBe($vessel->id)
        ->and($current['vessel_name'])->toBe($vessel->name)
        ->and($current['phase_id'])->toBe($assignment->current_phase_id)
        ->and(app(ActiveOnVesselAssignmentFinder::class)->recommendsTransfer($current, $vessel->id + 1))->toBeTrue();
});

test('employee with no active on vessel assignment is not recommended for transfer', function () {
    $fixtures = makeCrewAssignmentFixtures();

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    ))->toBeNull();
});

test('same vessel does not recommend a vessel transfer', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    $current = app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    );

    expect(app(ActiveOnVesselAssignmentFinder::class)->recommendsTransfer($current, $vessel->id))->toBeFalse();
});

test('transfer recommendation requires a selected destination vessel that differs from the current vessel', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $currentVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $destinationVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $currentVessel,
    );

    $finder = app(ActiveOnVesselAssignmentFinder::class);
    $current = $finder->find($fixtures['company']->id, $fixtures['employee']->id);

    expect($finder->recommendsTransfer(null, $destinationVessel->id))->toBeFalse()
        ->and($finder->recommendsTransfer($current, null))->toBeFalse()
        ->and($finder->recommendsTransfer($current, 0))->toBeFalse()
        ->and($finder->recommendsTransfer($current, -1))->toBeFalse()
        ->and($finder->recommendsTransfer($current, $currentVessel->id))->toBeFalse()
        ->and($finder->recommendsTransfer($current, $destinationVessel->id))->toBeTrue();
});

test('planned future assignment does not block an actual on vessel interval', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $plannedVessel = makeCrewMovementVessel('Future Plan Vessel', $fixtures['company']);
    $planned = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-PLAN-'.Str::upper(Str::random(4)),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $plannedVessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'planned_join_at' => '2026-09-01 08:00:00',
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $planned->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
        'planned_start_at' => '2026-09-01 08:00:00',
    ]);

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    ))->toBeNull();
});

test('cross company on vessel assignment is ignored and never exposed', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $other = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $other['company']);
    makeActiveOnVesselAssignment(
        $other['company'],
        $other['employee'],
        $other['rank'],
        $foreignVessel,
    );

    $current = app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    );

    expect($current)->toBeNull()
        ->and(app(ActiveOnVesselAssignmentFinder::class)->forCompany($fixtures['company']->id))->toBe([]);
});

test('open on vessel assignment is still identified for the recommendation', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $assignment->currentPhase?->update([
        'actual_start_at' => '2026-08-20 08:00:00',
    ]);

    $current = app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    );

    expect($current)->not->toBeNull()
        ->and($current['assignment_id'])->toBe($assignment->id)
        ->and(app(ActiveOnVesselAssignmentFinder::class)->recommendsTransfer($current, $vessel->id + 1))->toBeTrue();
});

test('exact timestamp handoff is not treated as a current on vessel conflict', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $assignment->currentPhase?->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-01 08:00:00',
        'actual_end_at' => '2026-08-26 16:30:00',
    ]);
    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    ))->toBeNull();
});

test('completed assignment does not recommend a current vessel transfer', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $assignment->currentPhase?->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-10 16:18:00',
        'actual_end_at' => '2026-08-23 16:25:00',
    ]);
    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    ))->toBeNull();
});

test('creating another draft is still blocked by the existing active assignment invariant', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $sourceVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $sourceVessel,
    );

    expect(fn () => app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['vessel_id' => makeCrewMovementVessel('PLB 648', $fixtures['company'])->id],
        $fixtures['user']->id,
    ))->toThrow(CrewMovementException::class, 'already has an active assignment');
});

test('transfer vessel still closes source and starts destination at the same timestamp', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $sourceVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $destinationVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $sourceVessel,
    );
    $source->currentPhase?->update(['actual_start_at' => '2026-08-01 08:00:00']);

    $destination = app(CrewMovementService::class)->perform(
        $fixtures['company']->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-08-26 16:30:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $fixtures['rank']->id,
        ],
        $fixtures['user']->id,
    );

    $source->refresh()->load('currentPhase');
    $destination->load('currentPhase');

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($source->currentPhase?->actual_end_at?->toDateTimeString())->toBe('2026-08-26 16:30:00')
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->source)->toBe('vessel_transfer')
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($destination->currentPhase?->actual_start_at?->toDateTimeString())->toBe('2026-08-26 16:30:00');
});

test('unauthorized user cannot perform transfer vessel', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $sourceVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $destinationVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $sourceVessel,
    );

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $source), [
            'action' => 'transfer_vessel',
            'occurred_at' => '2026-08-26 16:30:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $fixtures['rank']->id,
        ])
        ->assertForbidden();
});

test('create page exposes company scoped on vessel context for the recommendation', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.assignment_id',
                $assignment->id,
            )
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.vessel_name',
                $vessel->name,
            )
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.can_transfer',
                true,
            ));
});

test('historical p4 start correction remains available even if another on vessel interval exists', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $sourceVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $sourceVessel,
    );
    $source->currentPhase?->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-01 08:00:00',
        'actual_end_at' => '2026-08-31 16:30:00',
    ]);
    $source->update([
        'status' => CrewAssignmentStatus::Completed,
        'closed_at' => '2026-08-31 16:30:00',
    ]);

    $destinationVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    $destination = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-DEST-'.Str::upper(Str::random(4)),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $destinationVessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-08-31 16:30:00',
        'previous_assignment_id' => $source->id,
        'source' => 'vessel_transfer',
    ]);
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $destination->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-31 16:30:00',
    ]);
    $destination->update(['current_phase_id' => $phase->id]);
    $originalStart = $phase->fresh()->actual_start_at?->toDateTimeString();

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $destination->fresh(),
        $phase->fresh(),
        $requester,
        ['actual_start_at' => '2026-08-26 16:30:00'],
        'Backdated by mistake',
    );

    expect($phase->fresh()->actual_start_at?->toDateTimeString())->toBe($originalStart)
        ->and($correction->proposed_values['actual_start_at'] ?? null)->not->toBeNull();
});

test('exact boundary correction remains allowed', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $sourceVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $sourceVessel,
    );
    $source->currentPhase?->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-08-01 08:00:00',
        'actual_end_at' => '2026-08-26 16:30:00',
    ]);
    $source->update(['status' => CrewAssignmentStatus::Completed]);

    $destinationVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    $destination = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-DEST-'.Str::upper(Str::random(4)),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $destinationVessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-08-27 16:30:00',
        'source' => 'manual',
    ]);
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $destination->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-27 16:30:00',
    ]);
    $destination->update(['current_phase_id' => $phase->id]);

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $destination->fresh(),
        $phase->fresh(),
        $requester,
        ['actual_start_at' => '2026-08-26 16:30:00'],
        'Match the previous vessel end',
    );

    app(ApproveCrewMovementCorrection::class)->handle(
        $correction,
        $requester,
        (int) $fixtures['company']->id,
    );

    expect($phase->fresh()->actual_start_at?->toDateTimeString())->toBe('2026-08-26 16:30:00');
});

test('same assignment is excluded from its own on vessel recommendation', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        $assignment->id,
    ))->toBeNull();
});

test('cancelled assignment does not recommend a current vessel transfer', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $assignment->update(['status' => CrewAssignmentStatus::Cancelled]);

    expect(app(ActiveOnVesselAssignmentFinder::class)->find(
        $fixtures['company']->id,
        $fixtures['employee']->id,
    ))->toBeNull();
});

test('user without movement permission still sees recommendation context but cannot transfer', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.create',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.can_transfer',
                false,
            )
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.vessel_name',
                $vessel->name,
            ));
});

test('transfer recommendation action requires assignment view and movement permission', function (array $permissions, bool $canTransfer) {
    $fixtures = makeCrewAssignmentFixtures();
    $currentVessel = makeCrewMovementVessel('HEA KRAKEN', $fixtures['company']);
    $draftVessel = makeCrewMovementVessel('PLB 648', $fixtures['company']);
    $active = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $currentVessel,
    );
    $draft = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-DRAFT-'.Str::upper(Str::random(4)),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $draftVessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
    ]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);

    expect(CrewAssignmentPagePermissions::canTransfer($fixtures['user']))->toBe($canTransfer);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.can_transfer',
                $canTransfer,
            )
            ->where(
                'form_options.active_on_vessel_by_employee.'.$fixtures['employee']->id.'.assignment_id',
                $active->id,
            ));

    if (! in_array('crew_operations.assignments.view', $permissions, true)) {
        return;
    }

    $draft->load(['company', 'employee', 'rank', 'vessel', 'currentPhase', 'phases']);

    $detail = CrewAssignmentPresenter::detail($draft, $fixtures['user']);

    expect($detail['movement_context']['active_on_vessel_elsewhere']['assignment_id'] ?? null)->toBe($active->id)
        ->and($detail['movement_context']['active_on_vessel_elsewhere']['can_transfer'] ?? null)->toBe($canTransfer);
})->with([
    'view and perform' => [
        [
            'crew_operations.assignments.view',
            'crew_operations.assignments.create',
            'crew_operations.movements.perform',
        ],
        true,
    ],
    'view without perform' => [
        [
            'crew_operations.assignments.view',
            'crew_operations.assignments.create',
        ],
        false,
    ],
    'perform without view' => [
        [
            'crew_operations.assignments.create',
            'crew_operations.movements.perform',
        ],
        false,
    ],
]);
