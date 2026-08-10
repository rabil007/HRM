<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourProgress;
use App\Support\CrewMovements\CurrentCrewQuery;
use Carbon\CarbonImmutable;

/**
 * Build an active P4 On Vessel assignment with a configurable planned_signoff_at.
 * The P4 phase is started at ($today - $daysOnboard) so that exactly $daysOnboard
 * calendar days have elapsed (matching the "Phase Active Long" trigger if it were P4).
 */
function makeP4WithSignoff(
    int $daysOnboard,
    int $remainingDays,
    string $timezone = 'Asia/Dubai',
): CrewAssignment {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => $timezone]);
    $vessel = makeCrewMovementVessel('P4 Attention Vessel');

    $now = CarbonImmutable::now($timezone)->startOfDay();
    $joinedAt = $now->subDays($daysOnboard)->toDateTimeString();
    $signoffAt = $now->addDays($remainingDays)->toDateTimeString();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P4-'.strtoupper(substr(md5(microtime()), 0, 6)),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $joinedAt,
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $signoffAt,
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $joinedAt,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase', 'company', 'phases', 'employee', 'rank', 'vessel']);
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. P4 active > 14 days, healthy Tour > 30 days remaining → no phase_stale
// ─────────────────────────────────────────────────────────────────────────────
it('does not emit phase_stale for P4 active more than 14 days with healthy tour', function () {
    $assignment = makeP4WithSignoff(daysOnboard: 60, remainingDays: 30);

    $warnings = CrewMovementAttentionQuery::forAssignment($assignment);
    $codes = collect($warnings)->pluck('code');

    expect($codes)->not->toContain('phase_stale')
        ->and($codes)->not->toContain('Phase Active Long');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. P4 active 60 days, 30 days remaining → needs_attention false, due_within_30 info
// ─────────────────────────────────────────────────────────────────────────────
it('P4 60 days onboard 30 days remaining is not needs_attention and has no phase_stale', function () {
    $assignment = makeP4WithSignoff(daysOnboard: 60, remainingDays: 30);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $progress);
    $codes = collect($warnings)->pluck('code');

    // Tour status is informational — Due Within 30 Days
    expect($progress['tour_status'])->toBe(CrewTourStatus::DueWithin30Days->value)

        // But no attention warnings should exist
        ->and($codes)->not->toContain('phase_stale')
        ->and($codes)->not->toContain('tour_due_within_30_days')

        // Because due_within_30_days does not generate an attention warning,
        // needs_attention is false when warnings array is empty
        ->and($warnings)->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. P4 due within 14 days → Tour warning, needs_attention true, no phase_stale
// ─────────────────────────────────────────────────────────────────────────────
it('P4 due within 14 days generates tour warning and not phase_stale', function () {
    $assignment = makeP4WithSignoff(daysOnboard: 76, remainingDays: 10);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $progress);
    $codes = collect($warnings)->pluck('code');

    expect($progress['tour_status'])->toBe(CrewTourStatus::DueWithin14Days->value)
        ->and($codes)->toContain('tour_due_within_14_days')
        ->and($codes)->not->toContain('phase_stale')
        ->and($warnings)->not->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. P4 due within 7 days → critical Tour warning, no phase_stale
// ─────────────────────────────────────────────────────────────────────────────
it('P4 due within 7 days generates critical tour_due_within_7_days and no phase_stale', function () {
    $assignment = makeP4WithSignoff(daysOnboard: 84, remainingDays: 5);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $progress);
    $codes = collect($warnings)->pluck('code');

    expect($progress['tour_status'])->toBe(CrewTourStatus::DueWithin7Days->value)
        ->and($codes)->toContain('tour_due_within_7_days')
        ->and($codes)->not->toContain('phase_stale');

    $byCode = collect($warnings)->keyBy('code');
    expect($byCode['tour_due_within_7_days']['severity'])->toBe('critical');
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. P4 due today → critical tour_due_today, no phase_stale
// ─────────────────────────────────────────────────────────────────────────────
it('P4 due today generates tour_due_today and no phase_stale', function () {
    $timezone = 'Asia/Dubai';
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 14:00:00', $timezone));

    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => $timezone]);
    $vessel = makeCrewMovementVessel('P4 Due Today Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P4-TODAY-'.rand(1, 9999),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-05-12 08:00:00',
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => '2026-08-10 00:00:00', // due today
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-05-12 08:00:00',
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);
    $assignment->load(['currentPhase', 'company', 'phases', 'employee']);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $progress);
    $codes = collect($warnings)->pluck('code');

    expect($progress['tour_status'])->toBe(CrewTourStatus::DueToday->value)
        ->and($codes)->toContain('tour_due_today')
        ->and($codes)->not->toContain('phase_stale')
        ->and($codes)->not->toContain('planned_signoff_overdue');

    CarbonImmutable::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. P4 overdue → tour_overdue, no phase_stale or planned_signoff_overdue duplicate
// ─────────────────────────────────────────────────────────────────────────────
it('P4 overdue generates tour_overdue and suppresses planned_signoff_overdue and phase_stale', function () {
    $assignment = makeP4WithSignoff(daysOnboard: 95, remainingDays: -5);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $warnings = CrewMovementAttentionQuery::forAssignment($assignment, $progress);
    $codes = collect($warnings)->pluck('code');

    expect($progress['tour_status'])->toBe(CrewTourStatus::Overdue->value)
        ->and($codes)->toContain('tour_overdue')
        ->and($codes)->not->toContain('planned_signoff_overdue')
        ->and($codes)->not->toContain('phase_stale');
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. P4 missing Tour of Duty → warning remains
// ─────────────────────────────────────────────────────────────────────────────
it('P4 missing tour rule still generates missing_tour_of_duty warning', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P4 Missing Tour Vessel');

    $assignment = makeActiveOnVesselAssignment($fixtures['company'], $fixtures['employee'], $fixtures['rank'], $vessel, [
        'tour_of_duty_days' => null,
        'planned_signoff_at' => null,
    ]);
    $assignment->load(['currentPhase', 'company', 'phases']);

    $warnings = CrewMovementAttentionQuery::forAssignment($assignment);
    $codes = collect($warnings)->pluck('code');

    expect($codes)->toContain('missing_tour_of_duty')
        ->and($codes)->not->toContain('phase_stale');
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. P4 missing planned sign-off → warning remains
// ─────────────────────────────────────────────────────────────────────────────
it('P4 missing planned sign-off still generates missing_planned_signoff warning', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P4 Missing Signoff Vessel');

    $assignment = makeActiveOnVesselAssignment($fixtures['company'], $fixtures['employee'], $fixtures['rank'], $vessel, [
        'tour_of_duty_days' => 90,
        'planned_signoff_at' => null,
    ]);
    $assignment->load(['currentPhase', 'company', 'phases']);

    $warnings = CrewMovementAttentionQuery::forAssignment($assignment);
    $codes = collect($warnings)->pluck('code');

    expect($codes)->toContain('missing_planned_signoff')
        ->and($codes)->not->toContain('phase_stale');
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. Non-P4 phase active > 14 days still gets phase_stale
// ─────────────────────────────────────────────────────────────────────────────
it('non-P4 phase active more than 14 days still emits phase_stale', function () {
    $fixtures = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P3-STALE-'.rand(1, 9999),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => null,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => now()->subDays(30),
        'source' => 'manual',
    ]);

    // P3 ReadyToJoin phase started 20 days ago (> 14 day threshold)
    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now()->subDays(20)->toDateTimeString(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);
    $assignment->load(['currentPhase', 'company', 'phases']);

    $warnings = CrewMovementAttentionQuery::forAssignment($assignment);
    $codes = collect($warnings)->pluck('code');

    expect($codes)->toContain('phase_stale');
});

// ─────────────────────────────────────────────────────────────────────────────
// 10. Current Crew summary: healthy long-running P4 does not increment needs_attention
// ─────────────────────────────────────────────────────────────────────────────
it('summaryCounts does not increment needs_attention for healthy P4 with 30 days remaining', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';

    $now = CarbonImmutable::now($timezone)->startOfDay();

    // Create a P4 assignment that has been onboard for 60 days, 30 days remaining
    $joinedAt = $now->subDays(60)->toDateTimeString();
    $signoffAt = $now->addDays(30)->toDateTimeString();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P4-SUMMARY-'.rand(1, 9999),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => makeCrewMovementVessel('Summary P4 Vessel')->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $joinedAt,
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $signoffAt,
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $joinedAt,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $counts = CrewMovementAttentionQuery::summaryCounts($companyId);

    expect($counts['needs_attention'])->toBe(0)
        ->and($counts['total'])->toBeGreaterThanOrEqual(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 11. Current Crew Needs Attention filter: healthy P4 not returned
// ─────────────────────────────────────────────────────────────────────────────
it('movement_attention filter does not return healthy P4 with 30 days remaining', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';
    $now = CarbonImmutable::now($timezone)->startOfDay();

    // Healthy P4: 60 days onboard, 30 days remaining
    $joinedAt = $now->subDays(60)->toDateTimeString();
    $signoffAt = $now->addDays(30)->toDateTimeString();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P4-FILTER-'.rand(1, 9999),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => makeCrewMovementVessel('Filter P4 Vessel')->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $joinedAt,
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $signoffAt,
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $joinedAt,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $paginator = CurrentCrewQuery::paginate($companyId, ['movement_attention' => '1']);

    $returnedIds = collect($paginator->items())->pluck('id')->all();

    expect($returnedIds)->not->toContain($assignment->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// 12. Crew Movement History: healthy P4 duration accurate, needs_attention false
// ─────────────────────────────────────────────────────────────────────────────
it('CrewMovementHistoryPresenter yields needs_attention false and no Phase Active Long for healthy P4', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.crew_movement_history.view',
    ]);

    $timezone = $fixtures['company']->timezone ?? 'Asia/Dubai';
    $now = CarbonImmutable::now($timezone)->startOfDay();

    $joinedAt = $now->subDays(60);
    $signoffAt = $now->addDays(30);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-P4-HISTORY-'.rand(1, 9999),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => makeCrewMovementVessel('History P4 Vessel')->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $joinedAt->toDateTimeString(),
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $signoffAt->toDateTimeString(),
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $joinedAt->toDateTimeString(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.reports.crew-movement-history.index', [
            'search' => $assignment->assignment_no,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.needs_attention', false)
            ->where('assignments.0.warnings', [])
        );
});

// ─────────────────────────────────────────────────────────────────────────────
// 13. Tenant isolation: healthy P4 in a different company is not affected
// ─────────────────────────────────────────────────────────────────────────────
it('tenant isolation remains intact for P4 attention query', function () {
    // Company A — P4 with 30 days remaining (healthy)
    $fixturesA = makeCrewAssignmentFixtures();
    $companyA = (int) $fixturesA['company']->id;
    $timezone = $fixturesA['company']->timezone ?? 'Asia/Dubai';
    $now = CarbonImmutable::now($timezone)->startOfDay();

    $joinedA = $now->subDays(60)->toDateTimeString();
    $signoffA = $now->addDays(30)->toDateTimeString();

    $assignmentA = CrewAssignment::query()->create([
        'company_id' => $companyA,
        'assignment_no' => 'CA-P4-TENANT-A-'.rand(1, 9999),
        'employee_id' => $fixturesA['employee']->id,
        'rank_id' => $fixturesA['rank']->id,
        'vessel_id' => makeCrewMovementVessel('Tenant A Vessel')->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => $joinedA,
        'source' => 'manual',
        'tour_of_duty_days' => 90,
        'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty,
        'planned_signoff_at' => $signoffA,
    ]);

    $phaseA = CrewAssignmentPhase::query()->create([
        'company_id' => $companyA,
        'crew_assignment_id' => $assignmentA->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $joinedA,
    ]);
    $assignmentA->update(['current_phase_id' => $phaseA->id]);

    // Company B — separate tenant
    $fixturesB = makeCrewAssignmentFixtures();
    $companyB = (int) $fixturesB['company']->id;

    $countsA = CrewMovementAttentionQuery::summaryCounts($companyA);
    $countsB = CrewMovementAttentionQuery::summaryCounts($companyB);

    // Company A's healthy P4 does not inflate needs_attention
    expect($countsA['needs_attention'])->toBe(0)
        // Company B does not see Company A's assignments at all
        ->and($countsB['total'])->toBe(0);
});
