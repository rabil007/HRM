<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Support\CrewMovements\Historical\HistoricalCrewImportColumns;
use App\Support\CrewMovements\Historical\HistoricalCrewImportParser;
use App\Support\CrewMovements\Historical\HistoricalCrewImportTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeHistoricalImportOtherCompany(): Company
{
    $country = Country::first() ?? Country::query()->create(['code' => 'OC', 'name' => 'Other Land', 'dial_code' => '+002', 'is_active' => true]);
    $currency = Currency::first() ?? Currency::query()->create(['code' => 'OC', 'name' => 'Other Cur', 'symbol' => '$', 'is_active' => true]);

    return Company::query()->create([
        'name' => 'Other Company',
        'slug' => 'other-company-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeHistoricalCrewImportFile(array $rows, ?string $sheetName = null): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetName ?? HistoricalCrewImportTemplate::ASSIGNMENTS_SHEET);

    foreach (HistoricalCrewImportColumns::displayHeaders() as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $headerIndex = collect(HistoricalCrewImportColumns::headers())
        ->mapWithKeys(fn (string $header, int $index) => [$header => $index + 1])
        ->all();

    $rowNumber = HistoricalCrewImportParser::DATA_START_ROW;

    foreach ($rows as $row) {
        foreach ($row as $header => $value) {
            if (! isset($headerIndex[$header])) {
                continue;
            }

            $column = $headerIndex[$header];

            if ($header === HistoricalCrewImportColumns::EMPLOYEE_NO) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    $column,
                    $rowNumber,
                    (string) $value,
                    DataType::TYPE_STRING,
                );
            } elseif (is_float($value) || is_int($value)) {
                $sheet->setCellValueByColumnAndRow($column, $rowNumber, $value);
            } else {
                $sheet->setCellValueByColumnAndRow($column, $rowNumber, $value ?? '');
            }
        }

        $rowNumber++;
    }

    $path = tempnam(sys_get_temp_dir(), 'historical-crew-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile(
        $path,
        'historical-crew-import.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

test('authorized user can download historical import template with required sheets', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $inactiveRank = Rank::query()->create(['name' => 'Inactive Hist Rank '.uniqid(), 'is_active' => false]);
    $inactiveClient = Client::factory()->create(['name' => 'Inactive Hist Client '.uniqid(), 'is_active' => false]);
    $inactiveVessel = makeCrewMovementVessel('Inactive Hist Vessel '.uniqid(), $company, $inactiveClient);
    $inactiveVessel->update(['is_active' => false]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.template'));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain(HistoricalCrewImportTemplate::FILENAME);

    $tempPath = tempnam(sys_get_temp_dir(), 'hist-template-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    $spreadsheet = IOFactory::load($tempPath);
    expect($spreadsheet->getSheetByName(HistoricalCrewImportTemplate::INSTRUCTIONS_SHEET))->not->toBeNull()
        ->and($spreadsheet->getSheetByName(HistoricalCrewImportTemplate::ASSIGNMENTS_SHEET))->not->toBeNull()
        ->and($spreadsheet->getSheetByName(HistoricalCrewImportTemplate::REFERENCE_SHEET))->not->toBeNull();

    $assignments = $spreadsheet->getSheetByName(HistoricalCrewImportTemplate::ASSIGNMENTS_SHEET);
    expect((string) $assignments->getCellByColumnAndRow(1, 1)->getValue())->toContain('employee_no');

    $reference = $spreadsheet->getSheetByName(HistoricalCrewImportTemplate::REFERENCE_SHEET);
    $referenceValues = [];
    foreach ($reference->toArray() as $row) {
        foreach ($row as $cell) {
            if (is_string($cell) || is_numeric($cell)) {
                $referenceValues[] = (string) $cell;
            }
        }
    }

    expect($referenceValues)->toContain('3119')
        ->and($referenceValues)->toContain($inactiveVessel->name)
        ->and($referenceValues)->toContain($inactiveRank->name)
        ->and($referenceValues)->toContain($inactiveClient->name)
        ->and($referenceValues)->toContain('Inactive');

    @unlink($tempPath);
});

test('unauthorized user cannot download historical import template', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.template'))
        ->assertForbidden();
});

test('template reference data excludes employees hidden by visibility scope', function () {
    ['user' => $user, 'company' => $company, 'employee' => $visibleEmployee] = makeCrewAssignmentFixtures();
    $visibleEmployee->update(['employee_no' => 'VIS-100']);

    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Marine',
        'code' => 'HID'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);
    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Office',
        'code' => 'VIS'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);
    $visibleEmployee->update(['department_id' => $visibleDept->id]);

    $hiddenEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HID-200',
        'department_id' => $hiddenDept->id,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    restrictUserToDepartments($user, $company, [$visibleDept->id]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.template'));

    $response->assertOk();
    $tempPath = tempnam(sys_get_temp_dir(), 'hist-template-vis-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());
    $spreadsheet = IOFactory::load($tempPath);
    $reference = collect($spreadsheet->getSheetByName(HistoricalCrewImportTemplate::REFERENCE_SHEET)->toArray())
        ->flatten()
        ->map(fn ($v) => (string) $v)
        ->all();

    expect($reference)->toContain('VIS-100')
        ->and($reference)->not->toContain('HID-200')
        ->and($reference)->not->toContain($hiddenEmployee->name);

    @unlink($tempPath);
});

test('valid workbook validates as ready without persisting records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119', 'name' => 'Ranjan Rai']);
    $vessel = makeCrewMovementVessel('OMS 01 Hist', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $beforeAssignments = CrewAssignment::query()->count();
    $beforePhases = CrewAssignmentPhase::query()->count();
    $beforeSea = EmployeeSeaService::query()->count();
    $beforePlanning = CrewPlanningAssignment::query()->count();
    $beforeStays = CrewAccommodationStay::query()->count();

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.ready', 1)
        ->assertJsonPath('summary.blocked', 0)
        ->assertJsonPath('rows.0.status', 'ready')
        ->assertJsonPath('rows.0.employee.employee_no', '3119');

    expect(CrewAssignment::query()->count())->toBe($beforeAssignments)
        ->and(CrewAssignmentPhase::query()->count())->toBe($beforePhases)
        ->and(EmployeeSeaService::query()->count())->toBe($beforeSea)
        ->and(CrewPlanningAssignment::query()->count())->toBe($beforePlanning)
        ->and(CrewAccommodationStay::query()->count())->toBe($beforeStays);
});

test('unauthorized user cannot validate historical import', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('OMS Auth Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ])
        ->assertForbidden();
});

test('missing required sheet is rejected', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([], 'Wrong Sheet');

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('parser normalizes excel serial dates and rejects malformed dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '0042']);
    $vessel = makeCrewMovementVessel('Date Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $serial = ExcelDate::PHPToExcel(new DateTimeImmutable('2024-03-05'));

    $good = makeHistoricalCrewImportFile([
        [
            'employee_no' => '0042',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => $serial,
            'disembark_date' => '2024-08-10',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $good,
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.joined_vessel_at', '2024-03-05')
        ->assertJsonPath('rows.0.employee.employee_no', '0042');

    $bad = makeHistoricalCrewImportFile([
        [
            'employee_no' => '0042',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => 'not-a-date',
            'disembark_date' => '2024-08-10',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $bad,
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.status', 'blocked');
});

test('inactive master data rows return warning when domain rules allow them', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119', 'status' => 'terminated']);
    $inactiveRank = Rank::query()->create(['name' => 'Chief Eng Inactive '.uniqid(), 'is_active' => false]);
    $inactiveClient = Client::factory()->create(['name' => 'Old Client '.uniqid(), 'is_active' => false]);
    $inactiveVessel = makeCrewMovementVessel('OMS Pearl Inactive '.uniqid(), $company, $inactiveClient);
    $inactiveVessel->update(['is_active' => false]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $inactiveVessel->name,
            'rank' => $inactiveRank->name,
            'client' => $inactiveClient->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.warning', 1)
        ->assertJsonPath('rows.0.status', 'warning');
});

test('employee not found and unknown vessel are blocked', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Known Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'MISSING-999',
            'vessel' => 'Totally Unknown Vessel',
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
        [
            'employee_no' => 'MISSING-999',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2023-06-01',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('summary.blocked', 2);
});

test('cross company vessel and hidden employee are blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $visible, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Office',
        'code' => 'VO'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Marine',
        'code' => 'HM'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);
    $visible->update(['employee_no' => 'VIS-1', 'department_id' => $visibleDept->id]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HID-1',
        'department_id' => $hiddenDept->id,
        'status' => 'active',
    ]);

    $otherCompany = makeHistoricalImportOtherCompany();
    $otherVessel = makeCrewMovementVessel('Other Co Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    restrictUserToDepartments($user, $company, [$visibleDept->id]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'HID-1',
            'vessel' => makeCrewMovementVessel('Local Vessel', $company)->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
        [
            'employee_no' => 'VIS-1',
            'vessel' => $otherVessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('summary.blocked', 2);

    $messages = collect($response->json('rows'))->pluck('errors')->flatten()->implode(' ');
    expect($messages)->toContain('not visible')
        ->and($messages)->toContain('not found');
});

test('future date chronology and existing assignment overlap are blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Overlap Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Seed an existing completed historical-like assignment via service path already tested
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.historical.store'), [
            'employee_id' => $employee->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'joined_vessel_at' => '2024-01-01',
            'disembarked_at' => '2024-06-30',
        ])
        ->assertRedirect();

    $future = now()->addMonth()->format('Y-m-d');
    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-05-15',
            'disembark_date' => '2024-07-10',
        ],
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => $future,
            'disembark_date' => now()->addMonths(2)->format('Y-m-d'),
        ],
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2022-12-01',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('summary.blocked', 3);
});

test('workbook exact duplicates and overlapping rows are detected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    Employee::factory()->forCompany($company)->create(['employee_no' => '3220', 'status' => 'active']);
    $vessel = makeCrewMovementVessel('WB Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $row = [
        'employee_no' => '3119',
        'vessel' => $vessel->name,
        'rank' => $rank->name,
        'vessel_join_date' => '2024-01-01',
        'disembark_date' => '2024-06-30',
    ];

    $file = makeHistoricalCrewImportFile([
        $row,
        $row, // exact duplicate
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-05-15',
            'disembark_date' => '2024-07-10',
        ],
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2023-01-01',
            'disembark_date' => '2023-06-30',
        ],
        [
            'employee_no' => '3220',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-01',
            'disembark_date' => '2024-06-30',
        ],
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ]);

    $response->assertOk();

    $statuses = collect($response->json('rows'))->pluck('status', 'row');
    expect($statuses[2])->toBe('blocked')
        ->and($statuses[3])->toBe('blocked')
        ->and($statuses[4])->toBe('blocked')
        ->and($statuses[5])->toBe('ready')
        ->and($statuses[6])->toBe('ready');
});

test('missing employee_no vessel join and disembark are blocked', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Required Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => '',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
            'remarks' => 'missing employee',
        ],
        [
            'employee_no' => 'X1',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '',
            'disembark_date' => '2024-07-20',
            'remarks' => 'missing join',
        ],
        [
            'employee_no' => 'X2',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '',
            'remarks' => 'missing disembark',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.blocked', 3);
});
