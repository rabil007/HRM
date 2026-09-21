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

        $highestRow = min($sheet->getHighestDataRow(), self::DATA_START_ROW + self::MAX_ROWS - 1);
        $rows = [];

        for ($rowNumber = self::DATA_START_ROW; $rowNumber <= $highestRow; $rowNumber++) {
            $raw = [];
            $fieldErrors = [];

            foreach (HistoricalCrewImportColumns::headers() as $header) {
                $columnIndex = $columnMap[$header] ?? null;

                if ($columnIndex === null) {
                    $raw[$header] = null;

                    continue;
                }

                if (in_array($header, HistoricalCrewImportColumns::dateHeaders(), true)) {
                    $parsed = $this->dateValue($sheet, $columnIndex, $rowNumber);

                    if ($parsed['error'] !== null) {
                        $fieldErrors[$header] = $parsed['error'];
                        $raw[$header] = null;
                    } else {
                        $raw[$header] = $parsed['value'];
                    }
                } elseif ($header === HistoricalCrewImportColumns::EMPLOYEE_NO) {
                    $raw[$header] = $this->employeeNoValue($sheet, $columnIndex, $rowNumber);
                } else {
                    $raw[$header] = $this->stringValue($sheet, $columnIndex, $rowNumber);
                }
            }

            $row = new HistoricalCrewImportRow($rowNumber, $raw, $fieldErrors);

            if ($row->isEmpty()) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, int> header => 1-based column index
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

    private function stringValue(Worksheet $sheet, int $column, int $row): ?string
    {
        $value = $sheet->getCellByColumnAndRow($column, $row)->getCalculatedValue();

        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || $string === '-') {
            return null;
        }

        return $string;
    }

    private function employeeNoValue(Worksheet $sheet, int $column, int $row): ?string
    {
        $cell = $sheet->getCellByColumnAndRow($column, $row);
        $value = $cell->getValue();

        if ($value === null || $value === '') {
            $calculated = $cell->getCalculatedValue();

            if ($calculated === null || $calculated === '') {
                return null;
            }

            $value = $calculated;
        }

        if (is_float($value) || is_int($value)) {
            // Preserve integer employee numbers without scientific notation / trailing .0
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
    private function dateValue(Worksheet $sheet, int $column, int $row): array
    {
        $cell = $sheet->getCellByColumnAndRow($column, $row);
        $value = $cell->getCalculatedValue();

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
