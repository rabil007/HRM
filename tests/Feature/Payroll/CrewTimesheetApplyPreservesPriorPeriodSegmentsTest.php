<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;

test('apply crew operations preparation preserves prior-period manual segment portion', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-06-25',
        'onsite_to' => '2026-07-15',
        'onsite_days' => 15,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-25',
        'to_date' => '2026-07-15',
        'days' => 21,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
    ]);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $approver,
        (int) $fixtures['company']->id,
    );

    $segments = CrewTimesheetSegment::query()
        ->where('crew_timesheet_id', $timesheet->id)
        ->orderBy('sequence')
        ->get();

    $priorManual = $segments->first(
        fn (CrewTimesheetSegment $segment): bool => $segment->source === CrewTimesheetSource::Manual
            && $segment->from_date?->toDateString() === '2026-06-25'
            && $segment->to_date?->toDateString() === '2026-06-30',
    );

    expect($priorManual)->not->toBeNull()
        ->and((float) $priorManual->days)->toBe(6.0)
        ->and($priorManual->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and((int) $priorManual->vessel_id)->toBe((int) $fixtures['vessel']->id);

    $crewOps = $segments->where('source', CrewTimesheetSource::CrewOperations);
    expect($crewOps)->not->toBeEmpty();

    foreach ($crewOps as $segment) {
        expect($segment->from_date?->toDateString() >= '2026-07-01')->toBeTrue()
            ->and($segment->to_date?->toDateString() <= '2026-07-31')->toBeTrue();
    }

    // Cross-period Manual original must no longer be active (replaced by prior clip + CO).
    expect(CrewTimesheetSegment::query()
        ->where('crew_timesheet_id', $timesheet->id)
        ->where('source', CrewTimesheetSource::Manual)
        ->whereDate('to_date', '>=', '2026-07-01')
        ->count())->toBe(0)
        ->and(CrewTimesheetSegment::onlyTrashed()
            ->where('crew_timesheet_id', $timesheet->id)
            ->where('source', CrewTimesheetSource::Manual)
            ->count())->toBeGreaterThan(0);
});

test('apply preserves fully prior-period manual segments without clipping', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $priorOnly = CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-20',
        'to_date' => '2026-06-28',
        'days' => 9,
    ]);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $approver,
        (int) $fixtures['company']->id,
    );

    expect(CrewTimesheetSegment::query()->whereKey($priorOnly->id)->exists())->toBeTrue()
        ->and($priorOnly->fresh()->to_date?->toDateString())->toBe('2026-06-28');
});
