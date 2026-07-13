<?php

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeSalarySheetFixture(string $path): void
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Salary Sheet');

    $sheet->setCellValue('A1', 'CODE');
    $sheet->fromArray([
        'S/L NO.',
        'EMP.NO.',
        'NAME',
        'DESIGNATION',
        'CLIENT',
        'PROJECT',
        null,
        null,
        'DAYS',
        null,
        null,
        'DAYS',
        'BASIC SALARY ',
        'SUPPLIM ALLOW',
        'SITE ALLOW',
        'STAND BY',
        'ON SITE',
        'ADD / DED',
        'OT',
        'TOTAL SALARY',
        'PAYMENT METHOD',
    ], null, 'A2');

    $sheet->fromArray([
        1,
        2053,
        'MUHDIN KADIR',
        'MST',
        'VAN STEE',
        'CREWING',
        null,
        null,
        2,
        null,
        null,
        15,
        50,
        100,
        951,
        300,
        16515,
        16305.9,
        0,
        33120.9,
        'LOCAL BANK ACCOUNT',
    ], null, 'A3');

    $sheet->fromArray([
        2,
        2025,
        'VINOD MENON',
        'JUB PORT CAPT.',
        'NMDC ENERGY',
        'CREWING',
        null,
        null,
        0,
        null,
        null,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        55000,
        'LOCAL BANK ACCOUNT',
    ], null, 'A4');

    $sheet->fromArray([
        3,
        2050,
        'AHMED SAAD RAMADAN',
        'MSI',
        'TARGET',
        'CREWING',
        null,
        null,
        0,
        null,
        null,
        0,
        0,
        450,
        450,
        0,
        0,
        0,
        0,
        0,
        'LOCAL BANK ACCOUNT',
    ], null, 'A5');

    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();
}

test('authorized users can generate payslip zip from salary sheet without storing payroll data', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.payslips.generate',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'salary_sheet_').'.xlsx';
    makeSalarySheetFixture($path);

    $periodsBefore = PayrollPeriod::query()->count();
    $recordsBefore = PayrollRecord::query()->count();

    $response = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.from-salary-sheet'), [
            'file' => new UploadedFile($path, 'salary-sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'year' => 2026,
            'month' => 5,
        ]);

    $response->assertOk();
    expect(str_contains((string) $response->headers->get('content-disposition'), 'payslips-2026-05.zip'))->toBeTrue();

    $zipPath = $response->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->numFiles)->toBe(2);
    expect($zip->locateName('2053.pdf'))->not->toBeFalse();
    expect($zip->locateName('2025.pdf'))->not->toBeFalse();
    expect($zip->locateName('2050.pdf'))->toBeFalse();
    $zip->close();

    expect(PayrollPeriod::query()->count())->toBe($periodsBefore)
        ->and(PayrollRecord::query()->count())->toBe($recordsBefore);

    @unlink($path);
});

test('unauthorized users cannot generate payslips from salary sheet', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $path = tempnam(sys_get_temp_dir(), 'salary_sheet_').'.xlsx';
    makeSalarySheetFixture($path);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.from-salary-sheet'), [
            'file' => new UploadedFile($path, 'salary-sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'year' => 2026,
            'month' => 5,
        ])
        ->assertForbidden();

    @unlink($path);
});
