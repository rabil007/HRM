<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourStatus;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use Carbon\CarbonImmutable;

it('filters current crew by tour status overdue', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Filter Tour Vessel');
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    $overdue = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->subDays(3)->toDateTimeString(),
        ],
    );

    $otherEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $otherVessel = makeCrewMovementVessel('Filter Normal Vessel');
    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $otherEmployee,
        $fixtures['rank'],
        $otherVessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->addDays(60)->toDateTimeString(),
        ],
    );

    $paginator = CurrentCrewQuery::paginate((int) $fixtures['company']->id, [
        'tour_status' => CrewTourStatus::Overdue->value,
    ]);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->id)->toBe($overdue->id);
});

it('presenter returns tour progress with negative remaining days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Tour Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-10 00:00:00',
        ],
    );

    $phase = $assignment->currentPhase;
    $phase->update([
        'actual_start_at' => '2026-08-01 08:00:00',
        'status' => CrewPhaseStatus::Active,
        'phase_code' => CrewPhaseCode::OnVessel,
    ]);
    $assignment->update(['status' => CrewAssignmentStatus::Active]);
    $assignment->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'companyVisaType']);

    $item = CrewAssignmentPresenter::listItem($assignment);

    expect($item['tour_of_duty_days'])->toBe(90)
        ->and($item['days_onboard'])->toBe(19)
        ->and($item['current_duty_day'])->toBe(20)
        ->and($item['remaining_tour_days'])->toBeLessThan(0)
        ->and($item['tour_status'])->toBe(CrewTourStatus::Overdue->value);

    CarbonImmutable::setTestNow();
});

it('adds missing tour and overdue tour attention warnings', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Attention Tour Vessel');
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    $missing = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => null,
            'planned_signoff_at' => null,
        ],
    );
    $missing->load(['currentPhase', 'company', 'phases']);

    $codes = collect(CrewMovementAttentionQuery::forAssignment($missing))->pluck('code');
    expect($codes)->toContain('missing_tour_of_duty');

    $otherEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $overdue = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $otherEmployee,
        $fixtures['rank'],
        makeCrewMovementVessel('Attention Overdue Vessel'),
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->subDays(2)->toDateTimeString(),
        ],
    );
    $overdue->load(['currentPhase', 'company', 'phases']);

    $overdueCodes = collect(CrewMovementAttentionQuery::forAssignment($overdue))->pluck('code');
    expect($overdueCodes)->toContain('tour_overdue')
        ->and($overdueCodes)->not->toContain('planned_signoff_overdue');
});
