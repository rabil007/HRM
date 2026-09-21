<?php

namespace App\Support\CrewMovements\Historical;

use App\Support\Imports\FlexibleCsvDateParser;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class HistoricalCrewImportParser
{
    public const MAX_ROWS = 5000;

    public const DATA_START_ROW = 2;

    public const SAMPLE_EMPLOYEE_NO = 'EXAMPLE001';

    /**
     * @return list<HistoricalCrewImportRow>
     */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new \InvalidArgumentException('The uploaded spreadsheet could not be read.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('The uploaded file is not a readable spreadsheet workbook.');
        }

        $sheet = $spreadsheet->getSheetByName(HistoricalCrewImportTemplate::ASSIGNMENTS_SHEET);

        if ($sheet === null) {
            throw new \InvalidArgumentException(
                'The workbook must contain a sheet named "'.HistoricalCrewImportTemplate::ASSIGNMENTS_SHEET.'".',
            );
        }

        $columnMap = $this->resolveColumnMap($sheet);
        $this->assertRequiredHeaders($columnMap);

        $highestRow = $sheet->getHighestDataRow();
        $candidateRows = [];

        for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $raw = [];
            $fieldErrors = [];
            $hasFormula = false;

            foreach (HistoricalCrewImportColumns::headers() as $header) {
                $columnIndex = $columnMap[$header] ?? null;

                if ($columnIndex === null) {
                    $raw[$header] = null;

                    continue;
                }

                $cellResult = $this->readLiteralCell($sheet, $columnIndex, $rowNumber, $header);

                if ($cellResult['formula']) {
                    $hasFormula = true;
                    $fieldErrors[$header] = 'Formula values are not allowed in historical import data. Replace the formula with a plain value.';
                    $raw[$header] = null;

                    continue;
                }

                if ($cellResult['error'] !== null) {
                    $fieldErrors[$header] = $cellResult['error'];
                    $raw[$header] = null;

                    continue;
                }

                $raw[$header] = $cellResult['value'];
            }

            $row = new HistoricalCrewImportRow($rowNumber, $raw, $fieldErrors);

            if ($row->isEmpty() && ! $hasFormula) {
                continue;
            }

            if ($this->isUntouchedSampleRow($row)) {
                continue;
            }

            $candidateRows[] = $row;
        }

        $count = count($candidateRows);

        if ($count === 0) {
            throw new \InvalidArgumentException(
                'No historical assignment rows were found in the workbook.',
            );
        }

        if ($count > self::MAX_ROWS) {
            throw new \InvalidArgumentException(sprintf(
                'This workbook contains %s historical assignment rows. The maximum supported per upload is %s. Split the workbook into smaller files and upload them separately.',
                number_format($count),
                number_format(self::MAX_ROWS),
            ));
        }

        return $candidateRows;
    }

    private function isUntouchedSampleRow(HistoricalCrewImportRow $row): bool
    {
        $employeeNo = mb_strtoupper(trim((string) ($row->employeeNo() ?? '')));
        $remarks = mb_strtoupper(trim((string) ($row->remarks() ?? '')));

        return $employeeNo === self::SAMPLE_EMPLOYEE_NO
            || str_contains($remarks, 'SAMPLE — REPLACE')
            || str_contains($remarks, 'SAMPLE - REPLACE');
    }

    /**
     * @return array<string, int>
     */
    private function resolveColumnMap(Worksheet $sheet): array
    {
        $highestColumnIndex = Coordinate::columnIndexFromString(
            $sheet->getHighestDataColumn(1),
        );

        $map = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $header = HistoricalCrewImportColumns::normalizeHeader(
                (string) ($sheet->getCellByColumnAndRow($column, 1)->getValue() ?? ''),
            );

            if ($header === '') {
                continue;
            }

            if (isset($map[$header])) {
                throw new \InvalidArgumentException("Duplicate column header \"{$header}\" in Historical Assignments.");
            }

            $map[$header] = $column;
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $columnMap
     */
    private function assertRequiredHeaders(array $columnMap): void
    {
        $missing = [];

        foreach (HistoricalCrewImportColumns::requiredHeaders() as $header) {
            if (! isset($columnMap[$header])) {
                $missing[] = $header;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Historical Assignments is missing required column(s): '.implode(', ', $missing).'.',
            );
        }
    }

    /**
     * Read a literal cell value without evaluating formulas.
     *
     * @return array{value: ?string, error: ?string, formula: bool}
     */
    private function readLiteralCell(Worksheet $sheet, int $column, int $row, string $header): array
    {
        $cell = $sheet->getCellByColumnAndRow($column, $row);
        $value = $cell->getValue();

        if (HistoricalSpreadsheetSafeString::isFormulaCellValue($value)) {
            return ['value' => null, 'error' => null, 'formula' => true];
        }

        if ($value === null || $value === '') {
            return ['value' => null, 'error' => null, 'formula' => false];
        }

        if (in_array($header, HistoricalCrewImportColumns::dateHeaders(), true)) {
            return [...$this->normalizeDateLiteral($value), 'formula' => false];
        }

        if ($header === HistoricalCrewImportColumns::EMPLOYEE_NO) {
            return ['value' => $this->normalizeEmployeeNo($value), 'error' => null, 'formula' => false];
        }

        $string = trim((string) $value);

        if ($string === '' || $string === '-') {
            return ['value' => null, 'error' => null, 'formula' => false];
        }

        return ['value' => $string, 'error' => null, 'formula' => false];
    }

    private function normalizeEmployeeNo(mixed $value): ?string
    {
        if (is_float($value) || is_int($value)) {
            if (is_float($value) && floor($value) === $value) {
                return (string) (int) $value;
            }

            return trim((string) $value);
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function normalizeDateLiteral(mixed $value): array
    {
        if ($value === null || $value === '' || $value === '-') {
            return ['value' => null, 'error' => null];
        }

        if (is_numeric($value)) {
            try {
                $date = Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));

                return ['value' => $date->toDateString(), 'error' => null];
            } catch (\Throwable) {
                return ['value' => null, 'error' => 'Invalid Excel date value.'];
            }
        }

        $string = trim((string) $value);
        $parsed = FlexibleCsvDateParser::parse($string);

        if ($parsed === null) {
            return ['value' => null, 'error' => "Unrecognized date \"{$string}\". Use YYYY-MM-DD."];
        }

        return ['value' => $parsed->toDateString(), 'error' => null];
    }
}
