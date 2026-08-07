<?php

use App\Models\CrewMovementCorrection;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard surfaces overdue corrections in action required when permitted', function () {
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
            ->missing('movement_corrections')
            ->missing('alert_counts')
            ->where('can.corrections_view', true)
            ->where('action_required', fn ($items) => collect($items)->contains(
                fn (array $item): bool => $item['type'] === 'overdue_movement_correction'
                    && $item['problem'] === '2 correction(s) past review SLA'
                    && $item['href'] === route('organization.crew-movement-corrections.index'),
            )));

    CarbonImmutable::setTestNow();
});

test('dashboard omits correction actions without correction view permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    CrewMovementCorrection::factory()
        ->forAssignment($assignment, $assignment->currentPhase)
        ->pending()
        ->create([
            'requested_by' => $user->id,
            'requested_at' => now()->subDays(10),
        ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.corrections_view', false)
            ->missing('movement_corrections')
            ->where('action_required', fn ($items) => collect($items)
                ->doesntContain(fn (array $item): bool => $item['type'] === 'overdue_movement_correction')));
});
