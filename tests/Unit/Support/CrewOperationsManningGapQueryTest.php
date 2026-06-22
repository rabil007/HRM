<?php

use App\Models\EmployeeDeployment;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewOperationsManningGapQuery;
use Carbon\CarbonImmutable;

test('manning gap query returns understaffed positions when actual on-vessel count is below required', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $vessel = makeCrewDeploymentVessel('Gap Query Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'joined_date' => CarbonImmutable::today()->subDays(3),
    ]);

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
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $vessel = makeCrewDeploymentVessel('Fully Staffed Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(0)
        ->and($result['items'])->toBe([]);
});

test('manning gap query does not count deployments that are not on vessel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $vessel = makeCrewDeploymentVessel('Travelled Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'joined_date' => CarbonImmutable::today()->subDays(10),
        'disembarked_date' => CarbonImmutable::today()->subDays(5),
        'travelled_date' => CarbonImmutable::today()->subDays(4),
    ]);

    $result = CrewOperationsManningGapQuery::forCompany($company->id);

    expect($result['understaffed_positions'])->toBe(1)
        ->and($result['items'][0]['actual_count'])->toBe(0);
});
