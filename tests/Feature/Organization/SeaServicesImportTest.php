<?php

use App\Imports\SeaServicesImport;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\SeaServices\SeaServiceImportTemplateExporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('users without sea services import permission cannot download template', function () {
    ['user' => $user, 'company' => $company] = makeSeaServicesImportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.sea-services.import.template'))
        ->assertForbidden();
});

test('sea services template lists active employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSeaServicesImportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.import']);

    $result = app(SeaServiceImportTemplateExporter::class)->export($company->id);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Sea Services');

    $employeeNumbers = [];

    for ($row = SeaServicesImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->toContain($employee->employee_no);

    @unlink($result['path']);
});

test('sea services import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesImportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.import']);

    $file = makeSeaServicesImportFile([
        [
            'employee_no' => 'UNKNOWN-999',
            'name' => 'Unknown',
            'vessel_type' => $vesselType->name,
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'start_date' => '2024-01-15',
            'end_date' => '2024-06-15',
            'client' => null,
            'is_offshore' => 'yes',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.sea-services.import.preview'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'employee_no');
});

test('sea services import creates new records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'vesselType' => $vesselType, 'vessel' => $vessel, 'rank' => $rank] = makeSeaServicesImportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.import']);

    $file = makeSeaServicesImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => $vesselType->name,
            'vessel' => $vessel->name,
            'rank' => $rank->name,
            'start_date' => '2024-03-01',
            'end_date' => '2024-09-01',
            'client' => null,
            'is_offshore' => 'yes',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.sea-services.import'), [
            'file' => $file,
        ])
        ->assertRedirect(route('organization.sea-services'));

    $created = EmployeeSeaService::query()
        ->where('employee_id', $employee->id)
        ->where('vessel_id', $vessel->id)
        ->where('rank_id', $rank->id)
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->start_date?->toDateString())->toBe('2024-03-01')
        ->and($created->end_date?->toDateString())->toBe('2024-09-01')
        ->and($created->is_offshore)->toBeTrue();
});

test('sea services import skips rows without sea service data', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeSeaServicesImportFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view', 'sea_services.import']);

    $file = makeSeaServicesImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'vessel_type' => null,
            'vessel' => null,
            'rank' => null,
            'start_date' => null,
            'end_date' => null,
            'client' => null,
            'is_offshore' => null,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.sea-services.import.preview'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJsonPath('summary.skipped', 1)
        ->assertJsonPath('rows.0.action', 'skip');
});

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     vesselType: VesselType,
 *     vessel: Vessel,
 *     rank: Rank
 * }
 */
function makeSeaServicesImportFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'SIM',
        'name' => 'Sea Import Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SIM',
        'name' => 'Sea Import Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Sea Import Co',
        'slug' => 'sea-import-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-SEA-01',
        'name' => 'Sea Worker',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Import Type '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'MV Import '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'Import Rank '.uniqid(),
        'is_active' => true,
    ]);

    return compact('user', 'company', 'employee', 'vesselType', 'vessel', 'rank');
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeSeaServicesImportFile(array $rows): UploadedFile
{
    $import = app(SeaServicesImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName());

    foreach ($import->headers() as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = SeaServicesImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['vessel_type'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['vessel'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['rank'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['start_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, $row['end_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['client'] ?? null);
        $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['is_offshore'] ?? null);

        $rowNumber++;
    }

    $path = storage_path('app/temp/'.uniqid('sea-services-import-test-', true).'.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'sea-services-import.xlsx', null, null, true);
}
