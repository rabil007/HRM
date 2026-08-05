<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Client;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Rank;
use App\Models\User;
use App\Support\Payroll\CrewTimesheetImportSchema;
use App\Support\Payroll\ValidateCrewTimesheetOperationalIntegrity;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     period: PayrollPeriod,
 *     employee: Employee,
 *     timesheet: CrewTimesheet
 * }
 */
function makePriorPeriodArrearsTimesheetFixtures(): array
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
    $employee = createCrewEmployeeWithContract($company, 'PPA-'.uniqid(), 100, 50, 25);
    $vessel = makeCrewMovementVessel('PPA Vessel');
    $client = Client::query()->create(['name' => 'PPA Client '.uniqid(), 'is_active' => true]);
    $rank = Rank::query()->create(['name' => 'PPA Rank '.uniqid(), 'is_active' => true]);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'approved_at' => now(),
        'approved_by' => $user->id,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-11',
        'onsite_days' => 11,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-11',
        'days' => 11,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
        'rank_id' => $rank->id,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $company->id,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 2,
        'source' => CrewTimesheetSource::Manual,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-20',
        'to_date' => '2026-07-31',
        'days' => 12,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
        'rank_id' => $rank->id,
    ]);

    return [
        'user' => $user,
        'company' => $company,
        'period' => $period,
        'employee' => $employee,
        'timesheet' => $timesheet,
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makePriorPeriodArrearsImportFile(int $companyId, array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

    $headers = app(CrewTimesheetImportSchema::class)->headers($companyId);

    foreach ($headers as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $headerIndexByName = collect($headers)
        ->mapWithKeys(fn (string $header, int $index) => [$header => $index + 1])
        ->all();

    $rowNumber = CrewTimesheetsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $setCell = function (string $header, mixed $value) use ($sheet, $headerIndexByName, $rowNumber): void {
            if (! isset($headerIndexByName[$header])) {
                return;
            }

            $sheet->setCellValueByColumnAndRow($headerIndexByName[$header], $rowNumber, $value ?? '');
        };

        $setCell('Employee No', $row['employee_no'] ?? '');
        $setCell('Employee Name', $row['name'] ?? '');
        $setCell('Onsite From', $row['onsite_from'] ?? '');
        $setCell('Onsite To', $row['onsite_to'] ?? '');
        $setCell('Overtime Hours', $row['overtime_hours'] ?? '');

        $rowNumber++;
    }

    $path = tempnam(sys_get_temp_dir(), 'crew-import-ppa-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'crew-timesheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('daily crew segment save keeps original cross-period range as one segment', function () {
    $fixtures = makePriorPeriodArrearsTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-06-28',
                    'to_date' => '2026-07-05',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $timesheet = $fixtures['timesheet']->fresh(['segments']);

    expect($timesheet->segments)->toHaveCount(1)
        ->and($timesheet->segments->first()->from_date?->toDateString())->toBe('2026-06-28')
        ->and($timesheet->segments->first()->to_date?->toDateString())->toBe('2026-07-05')
        ->and((float) $timesheet->segments->first()->days)->toBe(8.0)
        ->and($timesheet->segments->first()->source)->toBe(CrewTimesheetSource::Manual)
        // Parent present days count only the current-period portion.
        ->and((float) $timesheet->onsite_days)->toBe(5.0)
        ->and($timesheet->onsite_from?->toDateString())->toBe('2026-07-01')
        ->and($timesheet->onsite_to?->toDateString())->toBe('2026-07-05');
});

test('daily crew segment save rejects dates after period end but allows before start', function () {
    $fixtures = makePriorPeriodArrearsTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-06-20',
                    'to_date' => '2026-08-02',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('segments.0.to_date');

    expect($fixtures['timesheet']->fresh()->segments)->toHaveCount(2);
});

test('overlapping submitted ranges including prior portions are rejected', function () {
    $fixtures = makePriorPeriodArrearsTimesheetFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-06-28',
                    'to_date' => '2026-07-05',
                ],
                [
                    'pay_category' => CrewTimesheetPayCategory::SignOnStandby->value,
                    'from_date' => '2026-06-29',
                    'to_date' => '2026-07-02',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['segments.0.from_date', 'segments.1.from_date']);
});

test('replacing manual movements soft-deletes only manual import segments', function () {
    $fixtures = makePriorPeriodArrearsTimesheetFixtures();

    $manualSegment = CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $fixtures['timesheet']->id,
        'sequence' => 3,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-20',
        'to_date' => '2026-06-22',
        'days' => 3,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $crewOpsSegment = CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $fixtures['timesheet']->id,
        'sequence' => 4,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-10',
        'to_date' => '2026-06-12',
        'days' => 3,
        'source' => CrewTimesheetSource::CrewOperations,
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('payroll.timesheets.segments', [
            $fixtures['period'],
            $fixtures['timesheet'],
        ]), [
            'segments' => [
                [
                    'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                    'from_date' => '2026-06-25',
                    'to_date' => '2026-07-03',
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CrewTimesheetSegment::withTrashed()->find($manualSegment->id)?->trashed())->toBeTrue()
        ->and(CrewTimesheetSegment::query()->find($crewOpsSegment->id))->not->toBeNull()
        ->and($fixtures['timesheet']->fresh()->segments()->where('source', CrewTimesheetSource::Manual)->count())->toBe(1)
        ->and($fixtures['timesheet']->fresh()->segments()->where('source', CrewTimesheetSource::CrewOperations)->count())->toBe(1);
});

test('import preview allows prior dates and shows movement split', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, '2059', 50, 661, 611);

    $file = makePriorPeriodArrearsImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-06-28',
            'onsite_to' => '2026-07-05',
        ],
    ]);

    $preview = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.valid', 1)
        ->assertJsonPath('summary.invalid', 0)
        ->assertJsonPath('rows.0.prior_period_days', 3)
        ->assertJsonPath('rows.0.current_period_days', 5)
        ->json();

    expect($preview['rows'][0]['movement_split'])->toHaveCount(2);
});

test('import execute creates one cross-period segment without inflating parent days', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, '2059', 50, 661, 611);

    $file = makePriorPeriodArrearsImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-06-28',
            'onsite_to' => '2026-07-05',
            'overtime_hours' => 4,
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('period_id', $period->id)
        ->with(['segments'])
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::Import)
        ->and($timesheet->segments)->toHaveCount(1)
        ->and($timesheet->segments->first()->from_date?->toDateString())->toBe('2026-06-28')
        ->and($timesheet->segments->first()->to_date?->toDateString())->toBe('2026-07-05')
        ->and((float) $timesheet->onsite_days)->toBe(5.0)
        ->and((float) $timesheet->overtime_hours)->toBe(4.0);
});

test('import rejects dates after period end for daily crew', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    createCrewEmployeeWithContract($company, '2059', 50, 661, 611);

    $file = makePriorPeriodArrearsImportFile($company->id, [
        [
            'employee_no' => '2059',
            'name' => 'Hatem',
            'onsite_from' => '2026-06-28',
            'onsite_to' => '2026-08-02',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1);
});

test('operational integrity allows prior-only segments', function () {
    $fixtures = makePriorPeriodArrearsTimesheetFixtures();

    CrewTimesheetSegment::query()
        ->where('crew_timesheet_id', $fixtures['timesheet']->id)
        ->delete();

    CrewTimesheetSegment::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_id' => $fixtures['timesheet']->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-06-28',
        'to_date' => '2026-06-30',
        'days' => 3,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $timesheet = $fixtures['timesheet']->fresh(['segments']);
    $message = app(ValidateCrewTimesheetOperationalIntegrity::class)
        ->handle($timesheet, $fixtures['employee']);

    expect($message)->toBeNull();
});
