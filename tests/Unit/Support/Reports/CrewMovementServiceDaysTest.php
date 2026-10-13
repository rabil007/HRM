<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\Reports\CrewMovementServiceDays;
use Carbon\CarbonImmutable;

test('has_future_actual is set when actual end is after company-local today', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-04 08:00:00',
        'actual_end_at' => '2026-10-20 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases,
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-13', 'Asia/Dubai'),
    );

    expect($summary['has_future_actual'])->toBeTrue()
        ->and($summary['vessel_service_days'])->toBe(10);

    CarbonImmutable::setTestNow();
});
