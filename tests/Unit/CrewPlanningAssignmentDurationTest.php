<?php

use App\Support\CrewPlanning\CrewPlanningAssignmentDuration;

test('crew planning assignment duration counts same day as one inclusive day', function () {
    expect(CrewPlanningAssignmentDuration::inclusiveDays('2027-06-15', '2027-06-15'))->toBe(1);
});

test('crew planning assignment duration counts inclusive days across a span', function () {
    expect(CrewPlanningAssignmentDuration::inclusiveDays('2027-02-01', '2027-08-31'))->toBe(212);
});

test('crew planning assignment duration counts short assignment span', function () {
    expect(CrewPlanningAssignmentDuration::inclusiveDays('2027-06-14', '2027-06-18'))->toBe(5);
});
