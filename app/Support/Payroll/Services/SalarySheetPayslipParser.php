<?php

namespace App\Support\Payroll\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class SalarySheetPayslipParser
{
    public const MAX_ROWS = 500;

    public const SHEET_NAME = 'Salary Sheet';

    public const HEADER_ROW = 2;

    public const DATA_START_ROW = 3;

    private const COL_EMP_NO = 'B';

    private const COL_NAME = 'C';

    private const COL_DESIGNATION = 'D';

    private const COL_STANDBY_DAYS = 'I';

    private const COL_ONSITE_DAYS = 'L';

    private const COL_BASIC_SALARY = 'M';

    private const COL_SUPPLIM_ALLOW = 'N';

    private const COL_SITE_ALLOW = 'O';

    private const COL_STANDBY_PAY = 'P';

    private const COL_ONSITE_PAY = 'Q';

    private const COL_ADD_DED = 'R';

    private const COL_OT = 'S';

    private const COL_TOTAL = 'T';

    /**
     * @return list<array{
     *     row: int,
     *     employee_no: string,
     *     name: string,
     *     designation: string,
     *     total_standby_days: float,
     *     onsite_days: float,
     *     basic_salary: float,
     *     supplim_allow: float,
     *     site_allow: float,
     *     standby_pay: float,
     *     onsite_pay: float,
     *     add_ded: float,
     *     overtime_pay: float,
     *     total_salary: float
     * }>
     */
    public function parse(UploadedFile $file): array
    {
        $sheet = $this->resolveSheet($file);
        $this->assertValidTemplate($sheet);

        $rows = [];
        $highestRow = min($sheet->getHighestDataRow(), self::DATA_START_ROW + self::MAX_ROWS - 1);

        for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $employeeNo = $this->stringValue($sheet, self::COL_EMP_NO, $rowNumber);

            if ($employeeNo === null || $employeeNo === '') {
                continue;
            }

            $rows[] = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $this->stringValue($sheet, self::COL_NAME, $rowNumber) ?? '',
                'designation' => $this->stringValue($sheet, self::COL_DESIGNATION, $rowNumber) ?? '',
                'total_standby_days' => $this->numericValue($sheet, self::COL_STANDBY_DAYS, $rowNumber),
                'onsite_days' => $this->numericValue($sheet, self::COL_ONSITE_DAYS, $rowNumber),
                'basic_salary' => $this->numericValue($sheet, self::COL_BASIC_SALARY, $rowNumber),
                'supplim_allow' => $this->numericValue($sheet, self::COL_SUPPLIM_ALLOW, $rowNumber),
                'site_allow' => $this->numericValue($sheet, self::COL_SITE_ALLOW, $rowNumber),
                'standby_pay' => $this->numericValue($sheet, self::COL_STANDBY_PAY, $rowNumber),
                'onsite_pay' => $this->numericValue($sheet, self::COL_ONSITE_PAY, $rowNumber),
                'add_ded' => $this->numericValue($sheet, self::COL_ADD_DED, $rowNumber),
                'overtime_pay' => $this->numericValue($sheet, self::COL_OT, $rowNumber),
                'total_salary' => $this->numericValue($sheet, self::COL_TOTAL, $rowNumber),
            ];
        }

        usort(
            $rows,
            fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']),
        );

        return array_values($rows);
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
        $header = mb_strtoupper(trim((string) ($this->stringValue($sheet, self::COL_EMP_NO, self::HEADER_ROW) ?? '')));

        if (! in_array($header, ['EMP.NO.', 'EMP.NO', 'EMP NO', 'EMP NO.'], true)) {
            throw new \InvalidArgumentException('The uploaded file does not match the salary sheet template.');
        }
    }

    private function stringValue(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column.$row)->getCalculatedValue();

        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && ! is_string($value)) {
            if (fmod((float) $value, 1.0) === 0.0) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.');
        }

        $string = trim((string) $value);

        if ($string === '' || $string === '-') {
            return null;
        }

        return $string;
    }

    private function numericValue(Worksheet $sheet, string $column, int $row): float
    {
        $value = $sheet->getCell($column.$row)->getCalculatedValue();

        if ($value === null || $value === '' || $value === '-') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = str_replace([',', ' '], '', (string) $value);

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
}
