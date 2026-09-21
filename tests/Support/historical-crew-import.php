<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Support\CrewMovements\Historical\HistoricalCrewImportColumns;
use App\Support\CrewMovements\Historical\HistoricalCrewImportParser;
use App\Support\CrewMovements\Historical\HistoricalCrewImportTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function historicalImportIdempotencyKey(string $suffix = ''): string
{
    return str_pad('hist-import-'.Str::lower(Str::random(12)).$suffix, 16, '0');
}

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
            } elseif (is_string($value) && str_starts_with(ltrim($value), '=')) {
                // Store as a spreadsheet formula cell (never evaluated by the import parser).
                $sheet->setCellValueByColumnAndRow($column, $rowNumber, $value);
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
