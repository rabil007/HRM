<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewOperationsManningGapQuery;
use Carbon\CarbonImmutable;

test('manning gap query returns understaffed positions when actual on-vessel count is below required', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Gap Query Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(1)
        ->and($result['total_shortfall'])->toBe(1)
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['vessel_id'])->toBe($vessel->id)
        ->and($result['items'][0]['rank_id'])->toBe($rank->id)
        ->and($result['items'][0]['required_count'])->toBe(2)
        ->and($result['items'][0]['actual_count'])->toBe(1)
        ->and($result['items'][0]['gap'])->toBe(1);
});

test('manning gap query ignores positions that are fully staffed or overstaffed', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Fully Staffed Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(0)
        ->and($result['items'])->toBe([]);
});

test('manning gap query does not count assignments with completed P4 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Completed Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-COMPLETED',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => CarbonImmutable::today()->subDays(10),
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::today()->subDays(10),
        'actual_end_at' => CarbonImmutable::today()->subDays(5),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(1)
        ->and($result['items'][0]['actual_count'])->toBe(0);
});

test('manning gap query is scoped to company', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Multi Company Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    makeActiveOnVesselAssignment($otherCompany, $otherEmployee, $rank, $vessel);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(0);

    $otherResult = CrewOperationsManningGapQuery::forCompany($otherCompany->id);

    expect($otherResult['understaffed_positions'])->toBe(0);
});
