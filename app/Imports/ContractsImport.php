<?php

namespace App\Imports;

use App\Enums\PayrollCategory;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ContractsImport
{
    public const MAX_ROWS = 5000;

    public const DATA_START_ROW = 2;

    private const COL_EMP_NO = 'A';

    private const COL_NAME = 'B';

    private const COL_CONTRACT_TYPE = 'C';

    private const COL_START_DATE = 'D';

    private const COL_END_DATE = 'E';

    private const COL_LABOR_CONTRACT_ID = 'F';

    private const COL_STATUS = 'G';

    private const COL_BASIC_SALARY = 'H';

    private const COL_OFFICE_HOUSING = 'I';

    private const COL_OFFICE_TRANSPORT = 'J';

    private const COL_OFFICE_OTHER = 'K';

    private const COL_CREW_SUPPLEMENTARY = 'I';

    private const COL_CREW_SITE = 'J';

    private const COL_OFFICE_NOTE = 'L';

    private const COL_CREW_NOTE = 'K';

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(UploadedFile $file, PayrollCategory $payrollCategory): array
    {
        $sheet = $this->resolveSheet($file, $payrollCategory);
        $this->assertValidTemplate($sheet, $payrollCategory);

        $rows = [];
        $highestRow = min($sheet->getHighestDataRow(), self::DATA_START_ROW + self::MAX_ROWS - 1);

        for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $employeeNo = $this->stringValue($sheet, self::COL_EMP_NO, $rowNumber);

            if ($this->shouldStopAtRow($employeeNo)) {
                break;
            }

            $row = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $this->stringValue($sheet, self::COL_NAME, $rowNumber),
                'contract_type' => $this->stringValue($sheet, self::COL_CONTRACT_TYPE, $rowNumber),
                'start_date' => $this->dateValue($sheet, self::COL_START_DATE, $rowNumber),
                'end_date' => $this->dateValue($sheet, self::COL_END_DATE, $rowNumber),
                'labor_contract_id' => $this->stringValue($sheet, self::COL_LABOR_CONTRACT_ID, $rowNumber),
                'status' => $this->stringValue($sheet, self::COL_STATUS, $rowNumber),
                'basic_salary' => $this->numericValue($sheet, self::COL_BASIC_SALARY, $rowNumber),
                'note' => $this->stringValue(
                    $sheet,
                    $payrollCategory === PayrollCategory::Office ? self::COL_OFFICE_NOTE : self::COL_CREW_NOTE,
                    $rowNumber,
                ),
            ];

            if ($payrollCategory === PayrollCategory::Office) {
                $row['housing_allowance'] = $this->numericValue($sheet, self::COL_OFFICE_HOUSING, $rowNumber);
                $row['transport_allowance'] = $this->numericValue($sheet, self::COL_OFFICE_TRANSPORT, $rowNumber);
                $row['other_allowances'] = $this->numericValue($sheet, self::COL_OFFICE_OTHER, $rowNumber);
                $row['supplementary_allowance'] = null;
                $row['site_allowance'] = null;
            } else {
                $row['housing_allowance'] = null;
                $row['transport_allowance'] = null;
                $row['other_allowances'] = null;
                $row['supplementary_allowance'] = $this->numericValue($sheet, self::COL_CREW_SUPPLEMENTARY, $rowNumber);
                $row['site_allowance'] = $this->numericValue($sheet, self::COL_CREW_SITE, $rowNumber);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function sheetName(PayrollCategory $payrollCategory): string
    {
        return $payrollCategory === PayrollCategory::Office
            ? 'Office Contracts'
            : 'Crew Contracts';
    }

    /**
     * @return list<string>
     */
    public function headers(PayrollCategory $payrollCategory): array
    {
        $shared = [
            'Employee No',
            'Employee Name',
            'Contract Type',
            'Start Date',
            'End Date',
            'Labor Contract ID',
            'Status',
            'Basic Salary',
        ];

        if ($payrollCategory === PayrollCategory::Office) {
            return [
                ...$shared,
                'Housing Allowance',
                'Transport Allowance',
                'Other Allowances',
                'Note',
            ];
        }

        return [
            ...$shared,
            'Supplementary Allowance',
            'Site Allowance',
            'Note',
        ];
    }

    private function resolveSheet(UploadedFile $file, PayrollCategory $payrollCategory): Worksheet
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheetName = $this->sheetName($payrollCategory);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            throw new \InvalidArgumentException('The uploaded file must contain a "'.$sheetName.'" worksheet.');
        }

        return $sheet;
    }

    private function assertValidTemplate(Worksheet $sheet, PayrollCategory $payrollCategory): void
    {
        $expectedHeaders = $this->headers($payrollCategory);

        foreach ($expectedHeaders as $index => $header) {
            $actual = mb_strtolower(trim((string) ($this->stringValue(
                $sheet,
                chr(ord('A') + $index),
                1,
            ) ?? '')));

            if ($actual !== mb_strtolower($header)) {
                throw new \InvalidArgumentException('The uploaded file does not match the '.$payrollCategory->label().' contracts template.');
            }
        }
    }

    private function shouldStopAtRow(?string $employeeNo): bool
    {
        return $employeeNo === null || $employeeNo === '';
    }

    private function stringValue(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column.$row)->getCalculatedValue();

        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || $string === '-') {
            return null;
        }

        return $string;
    }

    private function numericValue(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column.$row)->getCalculatedValue();

        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function dateValue(Worksheet $sheet, string $column, int $row): ?string
    {
        $cell = $sheet->getCell($column.$row);
        $value = $cell->getCalculatedValue();

        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        return $this->parseDateString(trim((string) $value));
    }

    private function parseDateString(string $value): ?string
    {
        foreach (['d-m-Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'm-d-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $value);

                if ($parsed !== false) {
                    return $parsed->toDateString();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
