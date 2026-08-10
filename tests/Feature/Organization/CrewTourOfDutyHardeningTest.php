<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourStatus;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourProgress;
use App\Support\CrewMovements\CrewTourStatusQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use Carbon\CarbonImmutable;

it('uses cumulative within-N-day filters matching daily pulse sign-off counts', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;
    $timezone = $fixtures['company']->timezone;
    $today = CarbonImmutable::now($timezone)->startOfDay();

    $slots = [
        ['label' => 'today', 'offset' => 0],
        ['label' => 'day5', 'offset' => 5],
        ['label' => 'day10', 'offset' => 10],
        ['label' => 'day20', 'offset' => 20],
        ['label' => 'day40', 'offset' => 40],
        ['label' => 'overdue', 'offset' => -2],
    ];

    foreach ($slots as $index => $slot) {
        $employee = $index === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]);

        makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            makeCrewMovementVessel("Cumulative {$slot['label']} Vessel"),
            [
                'tour_of_duty_days' => 90,
                'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
                'planned_signoff_at' => $today->addDays($slot['offset'])->toDateTimeString(),
            ],
        );
    }

    $buckets = (new CrewTourStatusQuery)->bucketCounts($companyId);
    $dashboard = app(CrewOperationsDashboardAnalytics::class)
        ->forCompany($companyId, $fixtures['user']);

    $within7 = CurrentCrewQuery::paginate($companyId, [
        'tour_status' => CrewTourStatus::DueWithin7Days->value,
    ])->total();
    $within14 = CurrentCrewQuery::paginate($companyId, [
        'tour_status' => CrewTourStatus::DueWithin14Days->value,
    ])->total();
    $within30 = CurrentCrewQuery::paginate($companyId, [
        'tour_status' => CrewTourStatus::DueWithin30Days->value,
    ])->total();
    $dueToday = CurrentCrewQuery::paginate($companyId, [
        'tour_status' => CrewTourStatus::DueToday->value,
    ])->total();
    $overdue = CurrentCrewQuery::paginate($companyId, [
        'tour_status' => CrewTourStatus::Overdue->value,
    ])->total();

    expect($within7)->toBe(2)
        ->and($within14)->toBe(3)
        ->and($within30)->toBe(4)
        ->and($dueToday)->toBe(1)
        ->and($overdue)->toBe(1)
        ->and($within7)->toBe(
            $buckets['due_within_7_days'] + $buckets['due_today'],
        )
        ->and($within14)->toBe(
            $buckets['due_within_14_days'] + $buckets['due_within_7_days'] + $buckets['due_today'],
        )
        ->and($within30)->toBe(
            $buckets['due_within_30_days']
            + $buckets['due_within_14_days']
            + $buckets['due_within_7_days']
            + $buckets['due_today'],
        )
        ->and($dashboard['daily_pulse']['signoffs_next_7_days'])->toBe($within7)
        ->and($dashboard['daily_pulse']['signoffs_overdue'])->toBe($overdue)
        ->and($dashboard)->not->toHaveKey('alert_counts');
});

it('does not mark due-today assignments as overdue at midday', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $timezone = $fixtures['company']->timezone ?: 'Asia/Dubai';
    $fixtures['company']->update(['timezone' => $timezone]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 15:30:00', $timezone));

    $today = CarbonImmutable::parse('2026-08-06', $timezone)->startOfDay();
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company']->fresh(),
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Due Today Midday Vessel'),
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->toDateTimeString(),
        ],
    );
    $assignment->load(['currentPhase', 'company', 'phases']);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $codes = collect(CrewMovementAttentionQuery::forAssignment($assignment, $progress))->pluck('code');

    expect($progress['tour_status'])->toBe(CrewTourStatus::DueToday->value)
        ->and($codes)->toContain('tour_due_today')
        ->and($codes)->not->toContain('planned_signoff_overdue')
        ->and($codes)->not->toContain('tour_overdue');

    CarbonImmutable::setTestNow();
});

it('freezes completed p4 progress at actual end and suppresses active tour alerts', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 12:00:00', 'Asia/Dubai'));

    $fixtures = makeCrewAssignmentFixtures();
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Completed Tour Vessel'),
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => '2026-08-10 00:00:00',
            'status' => CrewAssignmentStatus::Completed->value,
        ],
    );

    $phase = $assignment->currentPhase;
    $phase->update([
        'actual_start_at' => '2026-05-01 08:00:00',
        'actual_end_at' => '2026-08-05 18:00:00',
        'status' => CrewPhaseStatus::Completed,
        'phase_code' => CrewPhaseCode::OnVessel,
    ]);
    $assignment->update([
        'status' => CrewAssignmentStatus::Completed,
        'closed_at' => '2026-08-05 18:00:00',
    ]);
    $assignment->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'companyVisaType']);

    $progress = (new CrewTourProgress)->forAssignment($assignment);
    $codes = collect(CrewMovementAttentionQuery::forAssignment($assignment, $progress))->pluck('code');
    $item = CrewAssignmentPresenter::listItem($assignment);

    expect($progress['days_onboard'])->toBe(96)
        ->and($progress['remaining_tour_days'])->toBe(5)
        ->and($progress['tour_status'])->toBeNull()
        ->and($item['tour_status'])->toBeNull()
        ->and($codes)->not->toContain('tour_overdue')
        ->and($codes)->not->toContain('tour_due_today')
        ->and($codes)->not->toContain('planned_signoff_overdue');

    CarbonImmutable::setTestNow();
});
