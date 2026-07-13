<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class TrainingsImport
{
    public const MAX_ROWS = 5000;

    public const DATA_START_ROW = 2;

    private const COL_EMP_NO = 'A';

    private const COL_NAME = 'B';

    private const COL_COURSE = 'C';

    private const COL_ISSUE_DATE = 'D';

    private const COL_EXPIRY_DATE = 'E';

    private const COL_INSTITUTE = 'F';

    private const COL_COUNTRY = 'G';

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
                'course' => $this->stringValue($sheet, self::COL_COURSE, $rowNumber),
                'issue_date' => $this->dateValue($sheet, self::COL_ISSUE_DATE, $rowNumber),
                'expiry_date' => $this->dateValue($sheet, self::COL_EXPIRY_DATE, $rowNumber),
                'institute_center' => $this->stringValue($sheet, self::COL_INSTITUTE, $rowNumber),
                'country' => $this->stringValue($sheet, self::COL_COUNTRY, $rowNumber),
            ];
        }

        return $rows;
    }

    public function sheetName(): string
    {
        return 'Training';
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Employee No',
            'Employee Name',
            'Course',
            'Issue Date',
            'Expiry Date',
            'Institute Center',
            'Country',
        ];
    }

    private function resolveSheet(UploadedFile $file): Worksheet
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheetName = $this->sheetName();
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        if ($sheet === null) {
            throw new \InvalidArgumentException('The uploaded file must contain a valid worksheet.');
        }

        return $sheet;
    }

    private function assertValidTemplate(Worksheet $sheet): void
    {
        $expectedHeaders = $this->headers();

        foreach ($expectedHeaders as $index => $header) {
            $actual = mb_strtolower(trim((string) ($this->stringValue(
                $sheet,
                chr(ord('A') + $index),
                1,
            ) ?? '')));

            if ($actual !== mb_strtolower($header)) {
                throw new \InvalidArgumentException('The uploaded file does not match the Training template.');
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
