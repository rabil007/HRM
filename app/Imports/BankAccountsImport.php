<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BankAccountsImport
{
    public const MAX_ROWS = 5000;

    public const DATA_START_ROW = 2;

    private const COL_EMP_NO = 'A';

    private const COL_NAME = 'B';

    private const COL_BANK_NAME = 'C';

    private const COL_IBAN = 'D';

    private const COL_ACCOUNT_NAME = 'E';

    private const COL_IS_PRIMARY = 'F';

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
                'bank_name' => $this->stringValue($sheet, self::COL_BANK_NAME, $rowNumber),
                'iban' => $this->stringValue($sheet, self::COL_IBAN, $rowNumber),
                'account_name' => $this->stringValue($sheet, self::COL_ACCOUNT_NAME, $rowNumber),
                'is_primary' => $this->booleanValue($sheet, self::COL_IS_PRIMARY, $rowNumber),
            ];
        }

        return $rows;
    }

    public function sheetName(): string
    {
        return 'Bank Accounts';
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            'Employee No',
            'Employee Name',
            'Bank Name',
            'IBAN',
            'Account Name',
            'Is Primary',
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
                throw new \InvalidArgumentException('The uploaded file does not match the Bank Accounts template.');
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

    private function booleanValue(Worksheet $sheet, string $column, int $row): ?bool
    {
        $value = $this->stringValue($sheet, $column, $row);

        if ($value === null) {
            return null;
        }

        $val = mb_strtolower($value);

        if (in_array($val, ['yes', 'true', '1', 'y'], true)) {
            return true;
        }

        if (in_array($val, ['no', 'false', '0', 'n'], true)) {
            return false;
        }

        return null;
    }
}
