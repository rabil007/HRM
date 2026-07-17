<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function makeCorrectionAgeFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.corrections.view',
    ]);

    $vessel = makeCrewMovementVessel('Age Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    return [
        ...$fixtures,
        'assignment' => $assignment,
        'phase' => $assignment->currentPhase,
    ];
}

test('request status filter returns only overdue pending corrections', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionAgeFixtures();

    foreach ([1, 2, 4, 7] as $ageDays) {
        CrewMovementCorrection::factory()
            ->forAssignment($fixtures['assignment'], $fixtures['phase'])
            ->pending()
            ->create([
                'requested_by' => $fixtures['user']->id,
                'requested_at' => now()->subDays($ageDays),
            ]);
    }

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-movement-corrections.index', [
            'age_status' => 'overdue',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->has('corrections', 2)
            ->where('filters.age_status', 'overdue')
            ->where('summary_counts.pending', 4)
            ->where('summary_counts.needs_attention', 1)
            ->where('summary_counts.overdue', 2)
            ->where('corrections.0.age_status', 'overdue')
            ->where('corrections.1.age_status', 'overdue'));

    CarbonImmutable::setTestNow();
});

test('corrections sort overdue then needs attention then on time then newest decisions', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionAgeFixtures();

    $onTime = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'requested_at' => now()->subDay(),
        ]);
    $needsAttention = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'requested_at' => now()->subDays(2),
        ]);
    $overdue = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'requested_at' => now()->subDays(5),
        ]);
    $approved = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->approved()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'decided_by' => $fixtures['user']->id,
            'decided_at' => now(),
        ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-movement-corrections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('corrections.0.id', $overdue->id)
            ->where('corrections.1.id', $needsAttention->id)
            ->where('corrections.2.id', $onTime->id)
            ->where('corrections.3.id', $approved->id));

    CarbonImmutable::setTestNow();
});

test('detail exposes active pending age only for pending corrections', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionAgeFixtures();
    $pending = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'requested_at' => now()->subDays(6),
        ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-movement-corrections.show', $pending))
        ->assertInertia(fn (Assert $page) => $page
            ->where('correction.pending_days', 6)
            ->where('correction.pending_age_label', 'Pending for 6 days')
            ->where('correction.age_status', 'overdue')
            ->where('correction.age_status_label', 'Overdue')
            ->where('correction.overdue_days', 2));

    $pending->update([
        'status' => CrewMovementCorrectionStatus::Rejected,
        'decided_at' => now(),
        'decided_by' => $fixtures['user']->id,
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-movement-corrections.show', $pending))
        ->assertInertia(fn (Assert $page) => $page
            ->where('correction.pending_days', null)
            ->where('correction.pending_age_label', null)
            ->where('correction.age_status', 'not_applicable')
            ->where('correction.is_overdue', false));

    CarbonImmutable::setTestNow();
});
