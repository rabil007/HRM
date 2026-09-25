<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetBoardFilter;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewTimesheetResource;

test('crew timesheet mode labels and values match terminology standards', function () {
    expect(CrewTimesheetMode::CrewOperations->value)->toBe('crew_operations')
        ->and(CrewTimesheetMode::CrewOperations->label())->toBe('Crew Timesheet')
        ->and(CrewTimesheetMode::Manual->label())->toBe('Manual / Excel Timesheet')
        ->and(CrewTimesheetMode::Hybrid->label())->toBe('Crew Payroll');
});

test('crew timesheet source labels and values match terminology standards', function () {
    expect(CrewTimesheetSource::CrewOperations->value)->toBe('crew_operations')
        ->and(CrewTimesheetSource::CrewOperations->label())->toBe('Crew Assignments')
        ->and(CrewTimesheetSource::Manual->label())->toBe('Manual')
        ->and(CrewTimesheetSource::Import->label())->toBe('Import');
});

test('crew timesheet board filter labels match terminology standards', function () {
    expect(CrewTimesheetBoardFilter::CrewOperations->value)->toBe('crew_operations')
        ->and(CrewTimesheetBoardFilter::CrewOperations->label())->toBe('Crew Assignments')
        ->and(CrewTimesheetBoardFilter::MissingTimesheet->label())->toBe('Missing Timesheet')
        ->and(CrewTimesheetBoardFilter::tryFromQuery('ready'))->toBeNull()
        ->and(CrewTimesheetBoardFilter::tryFromQuery('awaiting_approval'))->toBeNull()
        ->and(CrewTimesheetBoardFilter::tryFromQuery('returned'))->toBeNull();
});

test('crew timesheet resource operational source label matches terminology standards', function () {
    $timesheet = new CrewTimesheet(['source' => CrewTimesheetSource::CrewOperations]);

    expect(CrewTimesheetResource::operationalSourceLabel($timesheet, ContractSalaryStructure::Daily))
        ->toBe('Crew Assignments');
});
