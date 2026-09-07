<?php

use App\Models\Employee;
use App\Support\CrewPlanning\CrewReliefDeskFilters;
use App\Support\CrewPlanning\CrewReliefDeskQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('keeps relief desk query count bounded as onboard rows grow', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $today = CarbonImmutable::parse('2026-09-07 08:00:00', $fixtures['company']->timezone ?? 'Asia/Dubai');
    CarbonImmutable::setTestNow($today);

    $makeOnboard = function (int $index) use ($fixtures, $today): void {
        $employee = Employee::factory()->forCompany($fixtures['company'])->create([
            'rank_id' => $fixtures['rank']->id,
            'status' => 'active',
        ]);

        makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            makeCrewMovementVessel("Desk Scale {$index}", $fixtures['company']),
            ['planned_signoff_at' => $today->addDays(10)->toDateTimeString()],
        );
    };

    $makeOnboard(0);

    $query = app(CrewReliefDeskQuery::class);
    $filters = CrewReliefDeskFilters::defaults();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $one = $query->page((int) $fixtures['company']->id, $filters, $fixtures['user'], 1, '/organization/crew-planning');
    $oneCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($one['pagination']->total())->toBe(1);

    for ($i = 1; $i < 12; $i++) {
        $makeOnboard($i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $many = $query->page((int) $fixtures['company']->id, $filters, $fixtures['user'], 1, '/organization/crew-planning');
    $manyCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many['pagination']->total())->toBe(12)
        ->and($manyCount)->toBeLessThanOrEqual($oneCount + 8)
        ->and($manyCount - $oneCount)->toBeLessThan(12);

    CarbonImmutable::setTestNow();
});
