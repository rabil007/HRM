<?php

namespace App\Support\Payroll\Services\Concerns;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait SpreadsheetPayrollExportFormatting
{
    protected const HEADER_ROW = 2;

    protected const DATA_START_ROW = 3;

    protected const MISSING_FILL = 'FF0000';

    protected const HEADER_FILL = 'DAE3F3';

    protected const DATE_FORMAT = '[$-10000]d\ mmm\ yyyy;@';

    protected function exportDepartmentLabel(Employee $employee): ?string
    {
        $department = $employee->department;

        if (filled($department?->parent?->name) && filled($department?->name)) {
            return (string) $department->name;
        }

        if (filled($department?->name)) {
            return (string) $department->name;
        }

        return null;
    }

    /**
     * @return array{value: mixed, missing: bool, is_date: bool}
     */
    protected function presentValue(mixed $value, bool $missing): array
    {
        return [
            'value' => $missing ? null : $value,
            'missing' => $missing,
            'is_date' => false,
        ];
    }

    /**
     * @return array{value: mixed, missing: bool, is_date: bool}
     */
    protected function presentDate(?CarbonInterface $date): array
    {
        if ($date === null) {
            return [
                'value' => '-',
                'missing' => true,
                'is_date' => false,
            ];
        }

        return [
            'value' => ExcelDate::PHPToExcel($date),
            'missing' => false,
            'is_date' => true,
        ];
    }

    /**
     * @return array{value: mixed, missing: bool, is_date: bool}
     */
    protected function presentNumeric(mixed $value, bool $missingWhenNull = true): array
    {
        if ($value === null || $value === '') {
            return [
                'value' => $missingWhenNull ? null : 0,
                'missing' => $missingWhenNull,
                'is_date' => false,
            ];
        }

        return [
            'value' => round((float) $value, 2),
            'missing' => false,
            'is_date' => false,
        ];
    }

    /**
     * @return array{value: mixed, missing: bool, is_date: bool}
     */
    protected function presentAdjustment(float $netAdjustment): array
    {
        if ($netAdjustment === 0.0) {
            return [
                'value' => null,
                'missing' => false,
                'is_date' => false,
            ];
        }

        return [
            'value' => $netAdjustment,
            'missing' => false,
            'is_date' => false,
        ];
    }

    /**
     * @param  array{value: mixed, missing: bool, is_date: bool}  $cell
     */
    protected function writeCell(
        Worksheet $sheet,
        string $coordinate,
        array $cell,
        bool $isDateColumn = false,
        string $employeeNumberColumn = 'B',
    ): void {
        $value = $cell['value'];

        if ($value === null) {
            $sheet->setCellValue($coordinate, null);
        } elseif ($cell['is_date']) {
            $sheet->setCellValue($coordinate, $value);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        } elseif (str_starts_with($coordinate, $employeeNumberColumn) && is_scalar($value)) {
            $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($coordinate, $value);
        }

        if ($isDateColumn && ! $cell['is_date'] && $value === '-') {
            $sheet->setCellValue($coordinate, '-');
        }
    }

    /**
     * @param  list<string>  $coordinates
     */
    protected function applyMissingHighlights(Worksheet $sheet, array $coordinates): void
    {
        foreach ($coordinates as $coordinate) {
            $sheet->getStyle($coordinate)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB(self::MISSING_FILL);
        }
    }

    protected function applyHeaderStyle(Worksheet $sheet, string $lastColumn): void
    {
        $sheet->getStyle('A'.self::HEADER_ROW.":{$lastColumn}".self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::HEADER_FILL],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);
    }

    protected function applyDataBorderStyle(Worksheet $sheet, string $lastColumn, int $lastDataRow): void
    {
        if ($lastDataRow < self::DATA_START_ROW) {
            return;
        }

        $sheet->getStyle('A'.self::DATA_START_ROW.":{$lastColumn}{$lastDataRow}")->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, int|float>  $columnWidths
     */
    protected function applyColumnWidths(Worksheet $sheet, array $columnWidths): void
    {
        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth((float) $width);
        }
    }

    protected function applyMoneyFormat(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    protected function applyDateFormatRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
    }

    protected function applyDaysFormat(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
    }

    /**
     * @return array{path: string, filename: string}
     */
    protected function saveSpreadsheet(Spreadsheet $spreadsheet, string $filenamePrefix, PayrollPeriod $period): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), $filenamePrefix);

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary payroll export file.');
        }

        $xlsxPath = $tempPath.'.xlsx';
        rename($tempPath, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);

        $slug = str($period->name ?? 'period-'.$period->id)->slug();

        return [
            'path' => $xlsxPath,
            'filename' => "{$filenamePrefix}{$slug}.xlsx",
        ];
    }

    protected function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }
}
