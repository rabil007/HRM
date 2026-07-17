<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function makeCorrectionSlaFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.corrections.view',
    ]);

    $vessel = makeCrewMovementVessel('SLA Vessel');
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

test('overdue filter returns only overdue pending corrections', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionSlaFixtures();

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
            'sla_status' => 'overdue',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->has('corrections', 2)
            ->where('filters.sla_status', 'overdue')
            ->where('summary_counts.pending', 4)
            ->where('summary_counts.attention', 1)
            ->where('summary_counts.overdue', 2)
            ->where('corrections.0.sla_status', 'overdue')
            ->where('corrections.1.sla_status', 'overdue'));

    CarbonImmutable::setTestNow();
});

test('corrections sort overdue then attention then normal then newest decisions', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionSlaFixtures();

    $normal = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $fixtures['phase'])
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'requested_at' => now()->subDay(),
        ]);
    $attention = CrewMovementCorrection::factory()
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
            ->where('corrections.1.id', $attention->id)
            ->where('corrections.2.id', $normal->id)
            ->where('corrections.3.id', $approved->id));

    CarbonImmutable::setTestNow();
});

test('detail exposes active SLA only for pending corrections', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    $fixtures = makeCorrectionSlaFixtures();
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
            ->where('correction.age_days', 6)
            ->where('correction.age_label', 'Pending for 6 days')
            ->where('correction.sla_status', 'overdue')
            ->where('correction.days_beyond_sla', 2));

    $pending->update([
        'status' => CrewMovementCorrectionStatus::Rejected,
        'decided_at' => now(),
        'decided_by' => $fixtures['user']->id,
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-movement-corrections.show', $pending))
        ->assertInertia(fn (Assert $page) => $page
            ->where('correction.age_days', null)
            ->where('correction.sla_status', 'not_applicable')
            ->where('correction.is_overdue', false));

    CarbonImmutable::setTestNow();
});
