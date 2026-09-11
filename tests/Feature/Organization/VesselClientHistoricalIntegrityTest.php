<?php

use App\Enums\CrewPhaseStatus;
use App\Models\Client;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\SeaServiceSyncService;
use Carbon\CarbonImmutable;

test('changing vessel client does not rewrite historical assignment or sea service client', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $adnoc = Client::query()->create(['name' => 'ADNOC Historical', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'NMDC Historical', 'is_active' => true]);

    $vessel = makeCrewMovementVessel('MV Falcon', $company);
    $vessel->update(['client_id' => $adnoc->id]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'client_id' => $adnoc->id,
    ]);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $seaService = app(SeaServiceSyncService::class)->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull()
        ->and((int) $seaService->client_id)->toBe((int) $adnoc->id)
        ->and((int) $assignment->fresh()->client_id)->toBe((int) $adnoc->id);

    $vessel->update(['client_id' => $nmdc->id]);

    expect((int) $vessel->fresh()->client_id)->toBe((int) $nmdc->id)
        ->and((int) $assignment->fresh()->client_id)->toBe((int) $adnoc->id)
        ->and((int) EmployeeSeaService::query()->whereKey($seaService->id)->value('client_id'))->toBe((int) $adnoc->id);
});
