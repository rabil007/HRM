<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewMovementCorrection;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionSla;
use Carbon\CarbonImmutable;

function correctionForSla(
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
    int $ageDays,
    string $expectedStatus,
    string $expectedLabel,
) {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForSla(
        CrewMovementCorrectionStatus::Pending,
        $now->subDays($ageDays),
    );

    $sla = app(CrewMovementCorrectionSla::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($sla['age_days'])->toBe($ageDays)
        ->and($sla['sla_status'])->toBe($expectedStatus)
        ->and($sla['age_label'])->toBe($expectedLabel)
        ->and($sla['is_attention'])->toBe($expectedStatus === 'attention')
        ->and($sla['is_overdue'])->toBe($expectedStatus === 'overdue');
})->with([
    'today' => [0, 'normal', 'Pending today'],
    'one completed day' => [1, 'normal', 'Pending for 1 day'],
    'two completed days' => [2, 'attention', 'Pending for 2 days'],
    'three completed days' => [3, 'attention', 'Pending for 3 days'],
    'four completed days' => [4, 'overdue', 'Pending for 4 days'],
    'seven completed days' => [7, 'overdue', 'Pending for 7 days'],
]);

it('does not keep SLA active after a correction is decided', function (
    CrewMovementCorrectionStatus $status,
) {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForSla($status, $now->subDays(10));

    $sla = app(CrewMovementCorrectionSla::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($sla['age_days'])->toBeNull()
        ->and($sla['sla_status'])->toBe('not_applicable')
        ->and($sla['is_attention'])->toBeFalse()
        ->and($sla['is_overdue'])->toBeFalse();
})->with([
    CrewMovementCorrectionStatus::Approved,
    CrewMovementCorrectionStatus::Rejected,
    CrewMovementCorrectionStatus::Cancelled,
]);

it('uses created at when requested at is missing', function () {
    $now = CarbonImmutable::parse('2026-07-17 12:00:00', 'Asia/Dubai');
    $correction = correctionForSla(
        CrewMovementCorrectionStatus::Pending,
        null,
        $now->subDays(3),
    );

    $sla = app(CrewMovementCorrectionSla::class)->forCorrection(
        $correction,
        'Asia/Dubai',
        $now,
    );

    expect($sla['age_days'])->toBe(3)
        ->and($sla['sla_status'])->toBe('attention');
});

it('calculates completed calendar days in the company timezone', function () {
    $correction = correctionForSla(
        CrewMovementCorrectionStatus::Pending,
        CarbonImmutable::parse('2026-07-15 06:00:00', 'Asia/Dubai'),
    );
    $now = CarbonImmutable::parse('2026-07-16 05:00:00', 'UTC');

    $sla = app(CrewMovementCorrectionSla::class)->forCorrection(
        $correction,
        'America/New_York',
        $now,
    );

    expect($sla['age_days'])->toBe(2)
        ->and($sla['sla_status'])->toBe('attention');
});
