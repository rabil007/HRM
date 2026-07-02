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

    public const SHEET_NAME = 'Crew Timesheets';

    public const DATA_START_ROW = 2;

    private const COL_EMP_NO = 'A';

    private const COL_NAME = 'B';

    private const COL_DEPARTMENT = 'C';

    private const COL_POSITION = 'D';

    private const COL_STANDBY_FROM = 'E';

    private const COL_STANDBY_TO = 'F';

    private const COL_STANDBY_DAYS = 'G';

    private const COL_ONSITE_FROM = 'H';

    private const COL_ONSITE_TO = 'I';

    private const COL_ONSITE_DAYS = 'J';

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

            if ($this->shouldStopAtRow($employeeNo)) {
                break;
            }

            $rows[] = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $this->stringValue($sheet, self::COL_NAME, $rowNumber),
                'department' => $this->stringValue($sheet, self::COL_DEPARTMENT, $rowNumber),
                'position' => $this->stringValue($sheet, self::COL_POSITION, $rowNumber),
                'standby_from' => $this->dateValue($sheet, self::COL_STANDBY_FROM, $rowNumber),
                'standby_to' => $this->dateValue($sheet, self::COL_STANDBY_TO, $rowNumber),
                'standby_days' => $this->numericValue($sheet, self::COL_STANDBY_DAYS, $rowNumber),
                'onsite_from' => $this->dateValue($sheet, self::COL_ONSITE_FROM, $rowNumber),
                'onsite_to' => $this->dateValue($sheet, self::COL_ONSITE_TO, $rowNumber),
                'onsite_days' => $this->numericValue($sheet, self::COL_ONSITE_DAYS, $rowNumber),
            ];
        }

        return $rows;
    }

    private function resolveSheet(UploadedFile $file): Worksheet
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        if ($sheet === null) {
            throw new \InvalidArgumentException('The uploaded file must contain a "'.self::SHEET_NAME.'" worksheet.');
        }

        return $sheet;
    }

    private function assertValidTemplate(Worksheet $sheet): void
    {
        $header = mb_strtolower(trim((string) ($this->stringValue($sheet, self::COL_EMP_NO, 1) ?? '')));

        if ($header !== 'employee no') {
            throw new \InvalidArgumentException('The uploaded file does not match the crew timesheet template.');
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
