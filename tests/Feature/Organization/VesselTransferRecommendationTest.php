<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Support\CrewMovements\ActiveOnVesselAssignmentFinder;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\OnVesselActualIntervalGuard;
use Carbon\CarbonImmutable;
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

    app(OnVesselActualIntervalGuard::class)->assertNoOverlap(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        CarbonImmutable::parse('2026-09-01 08:00:00'),
        null,
    );

    expect(true)->toBeTrue();
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

test('overlapping actual on vessel intervals are rejected', function () {
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

    expect(fn () => app(OnVesselActualIntervalGuard::class)->assertNoOverlap(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        CarbonImmutable::parse('2026-08-26 16:30:00'),
        null,
    ))->toThrow(CrewMovementException::class, 'Transfer Vessel');
});

test('exact timestamp handoff is allowed', function () {
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

    app(OnVesselActualIntervalGuard::class)->assertNoOverlap(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        CarbonImmutable::parse('2026-08-26 16:30:00'),
        null,
    );

    expect(true)->toBeTrue();
});

test('completed assignment with no overlap remains allowed', function () {
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

    app(OnVesselActualIntervalGuard::class)->assertNoOverlap(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        CarbonImmutable::parse('2026-08-26 16:30:00'),
        null,
    );

    expect(true)->toBeTrue();
});

test('backdated join that overlaps an open on vessel interval is rejected', function () {
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
    ))->toThrow(CrewMovementException::class, 'Transfer Vessel');
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

test('correction that would overlap another on vessel interval is rejected and does not change official dates', function () {
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

    expect(fn () => app(RequestCrewMovementCorrection::class)->handle(
        $destination->fresh(),
        $phase->fresh(),
        $requester,
        ['actual_start_at' => '2026-08-26 16:30:00'],
        'Backdated by mistake',
    ))->toThrow(CrewMovementException::class, 'overlap');

    expect($phase->fresh()->actual_start_at?->toDateTimeString())->toBe($originalStart)
        ->and(CrewMovementCorrection::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(0);
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

test('foreign company intervals cannot influence overlap validation', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $other = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $other['company']);
    $foreign = makeActiveOnVesselAssignment(
        $other['company'],
        $other['employee'],
        $other['rank'],
        $foreignVessel,
    );
    $foreign->currentPhase?->update(['actual_start_at' => '2026-08-01 08:00:00']);

    app(OnVesselActualIntervalGuard::class)->assertNoOverlap(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        CarbonImmutable::parse('2026-08-26 16:30:00'),
        null,
    );

    expect(true)->toBeTrue();
});
