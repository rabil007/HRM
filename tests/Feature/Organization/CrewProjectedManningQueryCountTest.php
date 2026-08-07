<?php

use App\Models\Employee;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use Illuminate\Support\Facades\DB;

it('keeps projected manning query count bounded as vessel and rank rows grow', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    $seedPositions = function (int $count) use ($fixtures): void {
        for ($i = 0; $i < $count; $i++) {
            $vessel = makeCrewMovementVessel("Projection Scale {$count}-{$i}");
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

            makeActiveOnVesselAssignment(
                $fixtures['company'],
                $employee,
                $fixtures['rank'],
                $vessel,
                ['planned_signoff_at' => now()->addDays(20)->toDateTimeString()],
            );
        }
    };

    $seedPositions(1);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = (new CrewProjectedManningQuery)->forCompany($companyId, '2026-08-01', '2026-08-31');
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $seedPositions(12);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $large = (new CrewProjectedManningQuery)->forCompany($companyId, '2026-08-01', '2026-08-31');
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($small['summary']['positions'])->toBe(1)
        ->and($large['summary']['positions'])->toBe(13)
        ->and($smallCount)->toBeGreaterThan(0)
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 6)
        ->and($largeCount - $smallCount)->toBeLessThan(13);
});
