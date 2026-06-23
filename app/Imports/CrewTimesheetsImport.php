<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class CrewTimesheetsImport
{
    public const MAX_ROWS = 500;

    public const SHEET_NAME = 'Salary Sheet';

    public const DATA_START_ROW = 5;

    private const COL_EMP_NO = 'B';

    private const COL_NAME = 'C';

    private const COL_DESIGNATION = 'D';

    private const COL_CLIENT = 'E';

    private const COL_PROJECT = 'F';

    private const COL_STANDBY_FROM = 'G';

    private const COL_STANDBY_TO = 'H';

    private const COL_STANDBY_DAYS = 'I';

    private const COL_ONSITE_FROM = 'J';

    private const COL_ONSITE_TO = 'K';

    private const COL_ONSITE_DAYS = 'L';

    private const COL_BASIC_RATE = 'M';

    private const COL_SUPPLEMENTARY_RATE = 'N';

    private const COL_SITE_RATE = 'O';

    private const COL_ADJUSTMENT = 'R';

    private const COL_OVERTIME = 'S';

    private const COL_PAYMENT_METHOD = 'U';

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $sheet = $this->resolveSheet($file);
        $this->assertValidTemplate($sheet);

        $rows = [];
        $highestRow = min($sheet->getHighestDataRow(), self::DATA_START_ROW + self::MAX_ROWS - 1);

        for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $employeeNo = $this->stringValue($sheet, self::COL_EMP_NO, $rowNumber);

            if ($this->shouldStopAtRow($sheet, $rowNumber, $employeeNo)) {
                break;
            }

            $adjustment = $this->numericValue($sheet, self::COL_ADJUSTMENT, $rowNumber);

            $rows[] = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $this->stringValue($sheet, self::COL_NAME, $rowNumber),
                'designation' => $this->stringValue($sheet, self::COL_DESIGNATION, $rowNumber),
                'client' => $this->stringValue($sheet, self::COL_CLIENT, $rowNumber),
                'project' => $this->stringValue($sheet, self::COL_PROJECT, $rowNumber),
                'standby_from' => $this->dateValue($sheet, self::COL_STANDBY_FROM, $rowNumber),
                'standby_to' => $this->dateValue($sheet, self::COL_STANDBY_TO, $rowNumber),
                'standby_days' => $this->numericValue($sheet, self::COL_STANDBY_DAYS, $rowNumber),
                'onsite_from' => $this->dateValue($sheet, self::COL_ONSITE_FROM, $rowNumber),
                'onsite_to' => $this->dateValue($sheet, self::COL_ONSITE_TO, $rowNumber),
                'onsite_days' => $this->numericValue($sheet, self::COL_ONSITE_DAYS, $rowNumber),
                'file_basic_rate' => $this->numericValue($sheet, self::COL_BASIC_RATE, $rowNumber),
                'file_supplementary_rate' => $this->numericValue($sheet, self::COL_SUPPLEMENTARY_RATE, $rowNumber),
                'file_site_rate' => $this->numericValue($sheet, self::COL_SITE_RATE, $rowNumber),
                'additional_amount' => $adjustment !== null && $adjustment > 0 ? $adjustment : 0.0,
                'deduction_amount' => $adjustment !== null && $adjustment < 0 ? abs($adjustment) : 0.0,
                'overtime_amount' => $this->numericValue($sheet, self::COL_OVERTIME, $rowNumber) ?? 0.0,
                'payment_method' => $this->stringValue($sheet, self::COL_PAYMENT_METHOD, $rowNumber),
            ];
        }

        return $rows;
    }

    private function resolveSheet(UploadedFile $file): Worksheet
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        if ($sheet === null) {
            throw new \InvalidArgumentException('The uploaded file must contain a "Salary Sheet" worksheet.');
        }

        return $sheet;
    }

    private function assertValidTemplate(Worksheet $sheet): void
    {
        $header = mb_strtoupper($this->stringValue($sheet, self::COL_EMP_NO, 2) ?? '');

        if (! str_contains($header, 'EMP')) {
            throw new \InvalidArgumentException('The uploaded file does not match the crew monthly timesheet template.');
        }
    }

    private function shouldStopAtRow(Worksheet $sheet, int $rowNumber, ?string $employeeNo): bool
    {
        if ($employeeNo === null || $employeeNo === '') {
            return true;
        }

        if (str_contains(mb_strtoupper($employeeNo), 'DATE')) {
            return true;
        }

        $firstCell = mb_strtoupper($this->stringValue($sheet, 'A', $rowNumber) ?? '');

        return str_contains($firstCell, 'DATE');
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

    private function numericValue(Worksheet $sheet, string $column, int $row): ?float
    {
        $value = $sheet->getCell($column.$row)->getCalculatedValue();

        if ($value === null || $value === '' || $value === '-') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
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

        $parsed = Carbon::createFromFormat('Y-m-d', (string) $value)
            ?: Carbon::createFromFormat('d/m/Y', (string) $value)
            ?: Carbon::createFromFormat('m/d/Y', (string) $value);

        return $parsed?->toDateString();
    }
}
