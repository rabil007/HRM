<?php

use App\Support\CrewPlanning\CrewPlanningOverlapValidator;

test('rangesOverlap treats adjacent date ranges as non-overlapping', function () {
    expect(CrewPlanningOverlapValidator::rangesOverlap(
        '2027-06-19',
        '2027-06-25',
        '2027-06-14',
        '2027-06-18',
    ))->toBeFalse();
});

test('rangesOverlap detects shared dates', function () {
    expect(CrewPlanningOverlapValidator::rangesOverlap(
        '2027-06-15',
        '2027-06-18',
        '2027-06-14',
        '2027-06-18',
    ))->toBeTrue();
});

test('rangesOverlap treats open-ended deployments as overlapping future plans', function () {
    expect(CrewPlanningOverlapValidator::rangesOverlap(
        '2027-06-15',
        '2027-06-18',
        '2027-06-01',
        null,
    ))->toBeTrue();
});
