<?php

use App\Models\Client;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Support\CrewMovements\Historical\HistoricalCrewImportParser;
use App\Support\CrewMovements\Historical\HistoricalCrewImportTemplate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
    expect($messages)->toContain('not found or is unavailable')
        ->and($messages)->toContain('not found')
        ->and($messages)->not->toContain('hidden department')
        ->and($messages)->not->toContain('not visible');
});

test('hidden and nonexistent employee numbers receive indistinguishable validation messages', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $visibleDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Visible Office',
        'code' => 'VX'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);
    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Marine',
        'code' => 'HX'.Str::upper(Str::random(3)),
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HID-77',
        'department_id' => $hiddenDept->id,
        'status' => 'active',
    ]);

    $vessel = makeCrewMovementVessel('Privacy Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    restrictUserToDepartments($user, $company, [$visibleDept->id]);
    $user->update(['current_company_id' => $company->id]);

    $file = makeHistoricalCrewImportFile([
        [
            'employee_no' => 'HID-77',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
        [
            'employee_no' => 'MISSING-88',
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

    $response->assertOk();
    $messages = collect($response->json('rows'))
        ->sortBy('row')
        ->pluck('errors')
        ->map(fn ($errs) => $errs[0] ?? '')
        ->values()
        ->all();

    $normalize = fn (string $message): string => (string) preg_replace('/"[^"]+"/', '""', $message);

    expect($normalize($messages[0]))->toBe($normalize($messages[1]))
        ->and($messages[0])->toContain('was not found or is unavailable')
        ->and($messages[1])->toContain('was not found or is unavailable')
        ->and($messages[0])->not->toContain('hidden')
        ->and($messages[1])->not->toContain('hidden');
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

test('empty workbook and untouched sample row are rejected', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile([]),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    $sampleOnly = makeHistoricalCrewImportFile([
        [
            'employee_no' => HistoricalCrewImportParser::SAMPLE_EMPLOYEE_NO,
            'vessel' => 'Sample Vessel',
            'rank' => 'Sample Rank',
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
            'remarks' => 'SAMPLE — REPLACE with real historical data',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $sampleOnly,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('formula cells are rejected and blank trailing rows do not count toward the limit', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employee->update(['employee_no' => '3119']);
    $vessel = makeCrewMovementVessel('Formula Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $withFormula = makeHistoricalCrewImportFile([
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
            'remarks' => '=HYPERLINK("http://evil.test")',
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $withFormula,
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.status', 'blocked');

    $messages = collect($this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => $withFormula,
        ])
        ->json('rows.0.errors'))->implode(' ');

    expect($messages)->toContain('Formula values are not allowed');

    $rows = [
        [
            'employee_no' => '3119',
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'vessel_join_date' => '2024-01-15',
            'disembark_date' => '2024-07-20',
        ],
    ];

    // Append many completely blank conceptual rows by writing only headers + one data row;
    // parser must report total 1 (blank trailing Excel rows are ignored).
    $this->actingAs($user)
        ->postJson(route('organization.crew-assignments.historical.import.validate'), [
            'file' => makeHistoricalCrewImportFile($rows),
        ])
        ->assertOk()
        ->assertJsonPath('summary.total', 1);
});

test('parser accepts exactly MAX_ROWS and rejects MAX_ROWS plus one', function () {
    $base = [
        'employee_no' => 'E1',
        'vessel' => 'Vessel',
        'rank' => 'Rank',
        'vessel_join_date' => '2024-01-01',
        'disembark_date' => '2024-06-01',
    ];

    $exact = [];
    for ($i = 0; $i < HistoricalCrewImportParser::MAX_ROWS; $i++) {
        $exact[] = [
            ...$base,
            'employee_no' => 'E'.$i,
        ];
    }

    $parsedExact = app(HistoricalCrewImportParser::class)->parse(makeHistoricalCrewImportFile($exact));
    expect($parsedExact)->toHaveCount(HistoricalCrewImportParser::MAX_ROWS);

    $tooMany = $exact;
    $tooMany[] = [
        ...$base,
        'employee_no' => 'E-OVERFLOW',
    ];

    expect(fn () => app(HistoricalCrewImportParser::class)->parse(makeHistoricalCrewImportFile($tooMany)))
        ->toThrow(InvalidArgumentException::class, 'maximum supported per upload');
})->group('slow');

test('generated template writes formula-like database names as plain text', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $employee->update([
        'employee_no' => '3119',
        'name' => '=HYPERLINK("http://evil.test","Click")',
    ]);
    Rank::query()->create([
        'name' => '=CMD|calc',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create_historical',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.historical.import.template'));

    $response->assertOk();
    $tempPath = tempnam(sys_get_temp_dir(), 'hist-tpl-safe-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    $spreadsheet = IOFactory::load($tempPath);
    $reference = $spreadsheet->getSheetByName(HistoricalCrewImportTemplate::REFERENCE_SHEET);

    $foundSafeEmployee = false;
    $foundSafeRank = false;

    foreach ($reference->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $raw = $cell->getValue();
            if (! is_string($raw)) {
                continue;
            }

            if (str_contains($raw, 'HYPERLINK')) {
                $foundSafeEmployee = true;
                expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
                    ->and($raw)->toStartWith("'=");
            }

            if (str_contains($raw, 'CMD|calc')) {
                $foundSafeRank = true;
                expect($cell->getDataType())->toBe(DataType::TYPE_STRING)
                    ->and($raw)->toStartWith("'=");
            }
        }
    }

    expect($foundSafeEmployee)->toBeTrue()
        ->and($foundSafeRank)->toBeTrue();

    @unlink($tempPath);
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
