<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionAge;
use Carbon\CarbonImmutable;

function correctionForAge(
    CrewMovementCorrectionStatus $status,
    ?CarbonImmutable $requestedAt,
    ?CarbonImmutable $createdAt = null,
): CrewMovementCorrection {
    return (new CrewMovementCorrection)->forceFill([
        'status' => $status,
        'requested_at' => $requestedAt,
        'created_at' => $createdAt ?? $requestedAt,
    ]);
}

it('classifies pending correction age thresholds', function (
    int $pendingDays,
    string $expectedStatus,
    string $expectedLabel,
) {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForAge(
        CrewMovementCorrectionStatus::Pending,
        $now->subDays($pendingDays),
    );

    $age = app(CrewMovementCorrectionAge::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($age['pending_days'])->toBe($pendingDays)
        ->and($age['age_status'])->toBe($expectedStatus)
        ->and($age['pending_age_label'])->toBe($expectedLabel)
        ->and($age['age_status_label'])->toBe(match ($expectedStatus) {
            'needs_attention' => 'Needs Attention',
            'overdue' => 'Overdue',
            default => 'On Time',
        })
        ->and($age['needs_attention'])->toBe($expectedStatus === 'needs_attention')
        ->and($age['is_overdue'])->toBe($expectedStatus === 'overdue')
        ->and($age['overdue_days'])->toBe(max(0, $pendingDays - 4));
})->with([
    'today' => [0, 'on_time', 'Pending today'],
    'one completed day' => [1, 'on_time', 'Pending for 1 day'],
    'two completed days' => [2, 'needs_attention', 'Pending for 2 days'],
    'three completed days' => [3, 'needs_attention', 'Pending for 3 days'],
    'four completed days' => [4, 'overdue', 'Pending for 4 days'],
    'seven completed days' => [7, 'overdue', 'Pending for 7 days'],
]);

it('does not keep age tracking active after a correction is decided', function (
    CrewMovementCorrectionStatus $status,
) {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForAge($status, $now->subDays(10));

    $age = app(CrewMovementCorrectionAge::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($age['pending_days'])->toBeNull()
        ->and($age['pending_age_label'])->toBeNull()
        ->and($age['age_status'])->toBe('not_applicable')
        ->and($age['age_status_label'])->toBeNull()
        ->and($age['needs_attention'])->toBeFalse()
        ->and($age['is_overdue'])->toBeFalse();
})->with([
    CrewMovementCorrectionStatus::Approved,
    CrewMovementCorrectionStatus::Rejected,
    CrewMovementCorrectionStatus::Cancelled,
]);

it('uses created at when requested at is missing', function () {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForAge(
        CrewMovementCorrectionStatus::Pending,
        null,
        $now->subDays(3),
    );

    $age = app(CrewMovementCorrectionAge::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($age['pending_days'])->toBe(3)
        ->and($age['age_status'])->toBe('needs_attention');
});

it('calculates completed calendar days in the company timezone', function () {
    $correction = correctionForAge(
        CrewMovementCorrectionStatus::Pending,
        CarbonImmutable::parse('2026-07-15 06:00:00', 'Asia/Dubai'),
    );
    $now = CarbonImmutable::parse('2026-07-16 05:00:00', 'UTC');

    $age = app(CrewMovementCorrectionAge::class)->forCorrection(
        $correction,
        'America/New_York',
        $now,
    );

    expect($age['pending_days'])->toBe(2)
        ->and($age['age_status'])->toBe('needs_attention');
});
