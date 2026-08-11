<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetMode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Models\Client;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     period: PayrollPeriod,
 *     employee: Employee,
 *     timesheet: CrewTimesheet,
 *     vesselA: Vessel,
 *     vesselB: Vessel,
 *     client: Client,
 *     rank: Rank,
 *     segmentIds: list<int>
 * }
 */
function makeMultiSegmentManualTimesheetFixtures(CrewTimesheetSource $source = CrewTimesheetSource::Manual): array
{
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'FS-'.uniqid(), 100, 50, 25);
    $vesselA = makeCrewMovementVessel('Vessel A');
    $vesselB = makeCrewMovementVessel('Vessel B');
    $client = Client::query()->create(['name' => 'FS Client '.uniqid(), 'is_active' => true]);
    $rank = Rank::query()->create(['name' => 'FS Rank '.uniqid(), 'is_active' => true]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => $source,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_from' => null,
        'onsite_to' => null,
        'onsite_days' => 23,
        'overtime_hours' => 2,
        'additional_amount' => 100,
        'deduction_amount' => 25,
        'remarks' => 'keep-me',
    ]);

    $segmentA = CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => $source,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-11',
        'days' => 11,
        'vessel_id' => $vesselA->id,
        'client_id' => $client->id,
        'rank_id' => $rank->id,
    ]);
    $segmentB = CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 2,
        'source' => $source,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-20',
        'to_date' => '2026-07-31',
        'days' => 12,
        'vessel_id' => $vesselB->id,
        'client_id' => $client->id,
        'rank_id' => $rank->id,
    ]);

    return [
        'user' => $user,
        'company' => $company,
        'period' => $period,
        'employee' => $employee,
        'timesheet' => $timesheet,
        'vesselA' => $vesselA,
        'vesselB' => $vesselB,
        'client' => $client,
        'rank' => $rank,
        'segmentIds' => [$segmentA->id, $segmentB->id],
    ];
}

test('financial autosave preserves two manual movement segments', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 9,
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect((float) $timesheet->overtime_hours)->toBe(9.0)
        ->and($timesheet->segments)->toHaveCount(2)
        ->and($timesheet->segments->pluck('id')->sort()->values()->all())
        ->toEqual(collect($fixtures['segmentIds'])->sort()->values()->all())
        ->and($timesheet->onsite_from)->toBeNull()
        ->and($timesheet->onsite_to)->toBeNull();
});

test('financial autosave preserves vessel client and rank metadata', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 6,
        ])
        ->assertRedirect();

    $segments = $fixtures['timesheet']->fresh()->segments()->orderBy('sequence')->get();

    expect((int) $segments[0]->vessel_id)->toBe($fixtures['vesselA']->id)
        ->and((int) $segments[0]->client_id)->toBe($fixtures['client']->id)
        ->and((int) $segments[0]->rank_id)->toBe($fixtures['rank']->id)
        ->and((int) $segments[1]->vessel_id)->toBe($fixtures['vesselB']->id)
        ->and((int) $segments[1]->client_id)->toBe($fixtures['client']->id)
        ->and((int) $segments[1]->rank_id)->toBe($fixtures['rank']->id);
});

test('financial autosave preserves import segments', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures(CrewTimesheetSource::Import);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 11,
            'additional_amount' => 150,
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect($timesheet->source)->toBe(CrewTimesheetSource::Import)
        ->and($timesheet->segments)->toHaveCount(2)
        ->and($timesheet->segments->every(fn ($segment) => $segment->source === CrewTimesheetSource::Import))->toBeTrue()
        ->and((float) $timesheet->overtime_hours)->toBe(11.0)
        ->and((float) $timesheet->additional_amount)->toBe(150.0);
});

test('financial update on crew operations applied timesheet preserves operational segments and linkage', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->with('segments')
        ->firstOrFail();

    $segmentIds = $timesheet->segments->pluck('id')->all();
    $preparationId = $timesheet->crew_timesheet_preparation_id;

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [$fixtures['period'], $timesheet]), [
            'overtime_hours' => 7,
            'additional_amount' => 40,
        ])
        ->assertRedirect();

    $fresh = $timesheet->fresh(['segments']);

    expect($fresh->isOperationallyLocked())->toBeTrue()
        ->and($fresh->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and((int) $fresh->crew_timesheet_preparation_id)->toBe((int) $preparationId)
        ->and($fresh->segments->pluck('id')->sort()->values()->all())
        ->toEqual(collect($segmentIds)->sort()->values()->all())
        ->and($fresh->segments->every(fn ($segment) => $segment->source === CrewTimesheetSource::CrewOperations))->toBeTrue()
        ->and((float) $fresh->overtime_hours)->toBe(7.0)
        ->and((float) $fresh->additional_amount)->toBe(40.0);
});

test('financial update changes only requested financial fields', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 12,
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh();

    expect((float) $timesheet->overtime_hours)->toBe(12.0)
        ->and((float) $timesheet->additional_amount)->toBe(100.0)
        ->and((float) $timesheet->deduction_amount)->toBe(25.0)
        ->and($timesheet->remarks)->toBe('keep-me');
});

test('missing financial fields preserve existing values', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'deduction_amount' => 55,
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh();

    expect((float) $timesheet->deduction_amount)->toBe(55.0)
        ->and((float) $timesheet->overtime_hours)->toBe(2.0)
        ->and((float) $timesheet->additional_amount)->toBe(100.0)
        ->and($timesheet->remarks)->toBe('keep-me');
});

test('submitted remarks null clears existing remarks', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'remarks' => null,
        ])
        ->assertRedirect();

    expect($fixtures['timesheet']->fresh()->remarks)->toBeNull()
        ->and((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(2.0)
        ->and($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('empty string remarks clears existing remarks', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'remarks' => '',
        ])
        ->assertRedirect();

    expect($fixtures['timesheet']->fresh()->remarks)->toBeNull();
});

test('omitted remarks preserves existing remarks', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 3,
        ])
        ->assertRedirect();

    expect($fixtures['timesheet']->fresh()->remarks)->toBe('keep-me');
});

test('null overtime_hours is normalized to zero', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => null,
        ])
        ->assertRedirect();

    expect((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(0.0)
        ->and($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('empty string overtime_hours is normalized to zero', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => '',
        ])
        ->assertRedirect();

    expect((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(0.0);
});

test('null overtime_amount additional_amount and deduction_amount normalize to zero', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_amount' => null,
            'additional_amount' => null,
            'deduction_amount' => null,
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh();

    expect((float) $timesheet->overtime_amount)->toBe(0.0)
        ->and((float) $timesheet->additional_amount)->toBe(0.0)
        ->and((float) $timesheet->deduction_amount)->toBe(0.0)
        ->and((float) $timesheet->overtime_hours)->toBe(2.0)
        ->and($timesheet->segments)->toHaveCount(2);
});

test('negative numeric financial values are rejected', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => -1,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('overtime_hours');

    expect((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(2.0)
        ->and($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('segment save replaces old manual segments', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    $replacementVessel = makeCrewMovementVessel('Replacement');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'vessel_id' => $replacementVessel->id,
                    'from_date' => '2026-07-05',
                    'to_date' => '2026-07-15',
                ],
            ],
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect($timesheet->segments)->toHaveCount(1)
        ->and(CrewTimesheetSegment::query()->whereIn('id', $fixtures['segmentIds'])->count())->toBe(0)
        ->and((int) $timesheet->segments->first()->vessel_id)->toBe($replacementVessel->id)
        ->and($timesheet->segments->first()->from_date?->toDateString())->toBe('2026-07-05')
        ->and($timesheet->segments->first()->to_date?->toDateString())->toBe('2026-07-15')
        ->and((float) $timesheet->onsite_days)->toBe(11.0);
});

test('segment save preserves overtime additions deductions and remarks', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                ],
            ],
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh();

    expect((float) $timesheet->overtime_hours)->toBe(2.0)
        ->and((float) $timesheet->additional_amount)->toBe(100.0)
        ->and((float) $timesheet->deduction_amount)->toBe(25.0)
        ->and($timesheet->remarks)->toBe('keep-me');
});

test('segment save preserves untouched assignment values', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'vessel_id' => $fixtures['vesselA']->id,
                    'client_id' => $fixtures['client']->id,
                    'rank_id' => $fixtures['rank']->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-12',
                    'remarks' => 'dates only',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $segment = $fixtures['timesheet']->fresh(['segments'])->segments->first();

    expect((int) $segment->vessel_id)->toBe($fixtures['vesselA']->id)
        ->and((int) $segment->client_id)->toBe($fixtures['client']->id)
        ->and((int) $segment->rank_id)->toBe($fixtures['rank']->id)
        ->and($segment->remarks)->toBe('dates only');
});

test('segment assignment values can be changed or cleared', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    $replacementVessel = makeCrewMovementVessel('Cleared Assignment Vessel');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'vessel_id' => $replacementVessel->id,
                    'client_id' => null,
                    'rank_id' => null,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $segment = $fixtures['timesheet']->fresh(['segments'])->segments->first();

    expect((int) $segment->vessel_id)->toBe($replacementVessel->id)
        ->and($segment->client_id)->toBeNull()
        ->and($segment->rank_id)->toBeNull();
});

test('payroll show exposes segment assignment values for the movement editor', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('payroll.show', $fixtures['period']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/show')
            ->has('rows')
            ->where('rows.0.timesheet.segments.0.vessel_id', $fixtures['vesselA']->id)
            ->where('rows.0.timesheet.segments.0.client_id', $fixtures['client']->id)
            ->where('rows.0.timesheet.segments.0.rank_id', $fixtures['rank']->id)
            ->where('rows.0.timesheet.is_operationally_locked', false)
            ->has('movement_master_options.vessels')
            ->has('movement_master_options.clients')
            ->has('movement_master_options.ranks'));
});

test('empty segments array is rejected', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments');

    expect($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('overlapping segments are rejected before deletion', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-15',
                ],
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-10',
                    'to_date' => '2026-07-20',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.1.from_date');

    expect(CrewTimesheetSegment::query()->whereIn('id', $fixtures['segmentIds'])->count())->toBe(2);
});

test('failed validation leaves old segments unchanged', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-08-01',
                    'to_date' => '2026-08-05',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors();

    $segments = $fixtures['timesheet']->fresh()->segments()->orderBy('sequence')->get();

    expect($segments)->toHaveCount(2)
        ->and($segments[0]->from_date?->toDateString())->toBe('2026-07-01')
        ->and($segments[1]->from_date?->toDateString())->toBe('2026-07-20');
});

test('segment save rejects inactive vessel client or rank masters', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    $inactiveVessel = makeCrewMovementVessel('Inactive');
    $inactiveVessel->update(['is_active' => false]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'vessel_id' => $inactiveVessel->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.0.vessel_id');

    expect(CrewTimesheetSegment::query()->whereIn('id', $fixtures['segmentIds'])->count())->toBe(2);
});

test('segment save rejects vessels from another company', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Timesheet Vessel', $otherCompany);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'vessel_id' => $foreignVessel->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.0.vessel_id');

    expect(CrewTimesheetSegment::query()->whereIn('id', $fixtures['segmentIds'])->count())->toBe(2)
        ->and((int) $fixtures['timesheet']->fresh()->segments()->orderBy('sequence')->first()->vessel_id)
        ->toBe((int) $fixtures['vesselA']->id);
});

test('timesheet store rejects segments that use another company vessel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'FS-FOREIGN-'.uniqid(), 100, 50, 25);
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Store Vessel', $otherCompany);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.show', $period))
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_hours' => 0,
            'additional_amount' => 0,
            'deduction_amount' => 0,
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'vessel_id' => $foreignVessel->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-10',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.0.vessel_id');

    expect(CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->exists())->toBeFalse();
});

test('crew operations segments cannot be replaced manually via segment route', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['period']->update(['crew_timesheet_mode' => CrewTimesheetMode::Hybrid]);
    grantApplyPermissions($fixtures['user'], $fixtures['company'], [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->with('segments')
        ->firstOrFail();
    $segmentIds = $timesheet->segments->pluck('id')->all();

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('payroll.show', $fixtures['period']))
        ->put(route('payroll.timesheets.segments', [$fixtures['period'], $timesheet]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-05',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments');

    expect(CrewTimesheetSegment::query()->whereIn('id', $segmentIds)->count())->toBe(count($segmentIds));
});

test('legacy flat operational payload still works when intentionally submitted', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.timesheets.store', $fixtures['period']), [
            'period_id' => $fixtures['period']->id,
            'employee_id' => $fixtures['employee']->id,
            'onsite_from' => '2026-07-01',
            'onsite_to' => '2026-07-18',
            'overtime_hours' => 3,
            'additional_amount' => 100,
            'deduction_amount' => 25,
            'remarks' => 'keep-me',
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect($timesheet->segments)->toHaveCount(1)
        ->and($timesheet->segments->first()->from_date?->toDateString())->toBe('2026-07-01')
        ->and($timesheet->segments->first()->to_date?->toDateString())->toBe('2026-07-18')
        ->and((float) $timesheet->onsite_days)->toBe(18.0)
        ->and((float) $timesheet->overtime_hours)->toBe(3.0);
});

test('financial-only legacy payload does not rebuild segments', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.timesheets.store', $fixtures['period']), [
            'period_id' => $fixtures['period']->id,
            'employee_id' => $fixtures['employee']->id,
            'overtime_hours' => 15,
            'additional_amount' => 100,
            'deduction_amount' => 25,
            'remarks' => 'keep-me',
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect((float) $timesheet->overtime_hours)->toBe(15.0)
        ->and($timesheet->segments)->toHaveCount(2)
        ->and($timesheet->segments->pluck('id')->sort()->values()->all())
        ->toEqual(collect($fixtures['segmentIds'])->sort()->values()->all())
        ->and($timesheet->onsite_from)->toBeNull();
});

test('sequential financial and segment updates preserve both changes', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 21,
            'remarks' => 'after-financial',
        ])
        ->assertRedirect();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-02',
                    'to_date' => '2026-07-12',
                ],
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-21',
                    'to_date' => '2026-07-31',
                ],
            ],
        ])
        ->assertRedirect();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect((float) $timesheet->overtime_hours)->toBe(21.0)
        ->and($timesheet->remarks)->toBe('after-financial')
        ->and($timesheet->segments)->toHaveCount(2)
        ->and($timesheet->segments->first()->from_date?->toDateString())->toBe('2026-07-02')
        ->and($timesheet->segments->last()->from_date?->toDateString())->toBe('2026-07-21');
});

test('unauthorized financial and segment requests are rejected', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    $outsider = User::factory()->create();
    $fixtures['company']->users()->attach($outsider->id, ['status' => 'active']);

    $this->actingAs($outsider)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'overtime_hours' => 99,
        ])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-05',
                ],
            ],
        ])
        ->assertForbidden();

    expect((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(2.0)
        ->and($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('tenant isolation is enforced for financial and segment routes', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();
    ['user' => $otherUser, 'company' => $otherCompany] = makePayrollFixtures();
    grantCompanyPermissions($otherUser, $otherCompany, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->patch(route('payroll.timesheets.financials', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            // Invalid payload must not surface as 422 for another tenant.
            'overtime_hours' => 'not-a-number',
        ])
        ->assertNotFound()
        ->assertSessionMissing('errors');

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'invalid-category',
                    'from_date' => 'not-a-date',
                    'to_date' => 'also-bad',
                ],
            ],
        ])
        ->assertNotFound()
        ->assertSessionMissing('errors');

    expect((float) $fixtures['timesheet']->fresh()->overtime_hours)->toBe(2.0)
        ->and($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('financial-only create via upsert does not create movement segments', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'FS-CREATE-'.uniqid(), 100, 50, 25);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'overtime_hours' => 5,
        ])
        ->assertRedirect();

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->with('segments')
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and((float) $timesheet->overtime_hours)->toBe(5.0)
        ->and($timesheet->segments)->toHaveCount(0);
});

test('vessel client and rank masters are global active-only not company-scoped', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    // Global masters remain usable across companies when active.
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => 'onsite',
                    'vessel_id' => $fixtures['vesselA']->id,
                    'client_id' => $fixtures['client']->id,
                    'rank_id' => $fixtures['rank']->id,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-08',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($fixtures['timesheet']->fresh()->segments)->toHaveCount(1);
});

test('upsert financial-only action call leaves segments untouched', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $updated = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        [
            'overtime_hours' => 4,
            'additional_amount' => 120,
        ],
        $fixtures['user']->id,
    );

    expect((float) $updated->overtime_hours)->toBe(4.0)
        ->and($updated->fresh()->segments)->toHaveCount(2)
        ->and($updated->fresh()->segments->pluck('id')->sort()->values()->all())
        ->toEqual(collect($fixtures['segmentIds'])->sort()->values()->all());
});

test('blank flat operational keys do not rebuild or wipe existing segments', function () {
    $fixtures = makeMultiSegmentManualTimesheetFixtures();

    $updated = app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        [
            'onsite_from' => null,
            'onsite_to' => null,
            'overtime_hours' => 8,
        ],
        $fixtures['user']->id,
    );

    expect((float) $updated->overtime_hours)->toBe(8.0)
        ->and(CrewTimesheetSegment::query()->whereIn('id', $fixtures['segmentIds'])->count())->toBe(2);
});

test('restored soft-deleted timesheet clears explicit null operational dates while updating overtime', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CLR-'.uniqid(), 100, 50, 25);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => '1900-04-01',
        'sign_on_standby_days' => 1,
        'overtime_hours' => 10,
    ]);

    $timesheet->delete();

    $updated = app(UpsertCrewTimesheet::class)->handle(
        $period,
        $employee,
        [
            'sign_on_standby_from' => null,
            'sign_on_standby_to' => null,
            'sign_on_standby_days' => null,
            'onsite_from' => null,
            'onsite_to' => null,
            'onsite_days' => null,
            'sign_off_standby_from' => null,
            'sign_off_standby_to' => null,
            'sign_off_standby_days' => null,
            'overtime_hours' => 92,
            'source' => CrewTimesheetSource::Import->value,
        ],
        $user->id,
    );

    expect($updated->trashed())->toBeFalse()
        ->and($updated->sign_on_standby_from)->toBeNull()
        ->and($updated->sign_on_standby_to)->toBeNull()
        ->and($updated->sign_on_standby_days)->toBeNull()
        ->and((float) $updated->overtime_hours)->toBe(92.0);
});
