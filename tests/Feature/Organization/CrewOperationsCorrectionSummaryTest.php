<?php

use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard correction summary is company scoped and contains only pending and overdue counts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai'));
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.corrections.view',
    ]);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    foreach ([1, 2, 4, 7] as $ageDays) {
        CrewMovementCorrection::factory()
            ->forAssignment($assignment, $assignment->currentPhase)
            ->pending()
            ->create([
                'requested_by' => $user->id,
                'requested_at' => now()->subDays($ageDays),
            ]);
    }

    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $assignment->currentPhase)
        ->approved()
        ->create([
            'requested_by' => $user->id,
            'decided_by' => $user->id,
        ]);

    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank, 'vessel' => $otherVessel] = makeCrewOperationsFixtures();
    $otherAssignment = makeActiveOnVesselAssignment(
        $otherCompany,
        $otherEmployee,
        $otherRank,
        $otherVessel,
    );
    CrewMovementCorrection::factory()
        ->forAssignment($otherAssignment, $otherAssignment->currentPhase)
        ->pending()
        ->create([
            'requested_by' => $user->id,
            'requested_at' => now()->subDays(8),
        ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movement_corrections', 3)
            ->where('movement_corrections.pending', 4)
            ->where('movement_corrections.overdue', 2)
            ->where(
                'movement_corrections.url',
                route('organization.crew-movement-corrections.index'),
            )
            ->missing('alert_counts.pending_corrections')
            ->where('attention_items', fn ($items) => collect($items)
                ->doesntContain(fn (array $item): bool => $item['type'] === 'pending_corrections')));

    CarbonImmutable::setTestNow();
});

test('dashboard omits correction summary without correction view permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $assignment->currentPhase)
        ->pending()
        ->create([
            'requested_by' => $user->id,
        ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.corrections_view', false)
            ->missing('movement_corrections'));
});
