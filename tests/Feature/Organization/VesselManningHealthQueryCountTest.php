<?php

use App\Models\Employee;
use App\Models\VesselManning;
use App\Support\VesselManning\VesselManningHealthQuery;
use Illuminate\Support\Facades\DB;

it('keeps vessel index health query count bounded as vessels grow', function () {
    $this->travelTo(now()->timezone('Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
    ]);

    $seed = function (int $count) use ($fixtures): void {
        for ($i = 0; $i < $count; $i++) {
            $vessel = makeCrewMovementVessel("Health Scale {$count}-{$i}");
            VesselManning::query()->create([
                'company_id' => $fixtures['company']->id,
                'vessel_id' => $vessel->id,
                'rank_id' => $fixtures['rank']->id,
                'required_count' => 1,
            ]);

            $employee = $i === 0 && $count === 1
                ? $fixtures['employee']
                : Employee::factory()->forCompany($fixtures['company'])->create([
                    'rank_id' => $fixtures['rank']->id,
                    'status' => 'active',
                ]);

            makeActiveOnVesselAssignment($fixtures['company'], $employee, $fixtures['rank'], $vessel);
        }
    };

    $seed(1);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = (new VesselManningHealthQuery)->compactByVessel((int) $fixtures['company']->id, $fixtures['user']);
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $seed(12);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $large = (new VesselManningHealthQuery)->compactByVessel((int) $fixtures['company']->id, $fixtures['user']);
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(count($small))->toBe(1)
        ->and(count($large))->toBe(13)
        ->and($smallCount)->toBeGreaterThan(0)
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 8)
        ->and($largeCount - $smallCount)->toBeLessThan(13);
});
