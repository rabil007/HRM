<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CrewPayrollSalarySheetExporter
{
    public const SHEET_NAME = 'Salary Sheet';

    private const HEADER_ROW = 2;

    private const DATA_START_ROW = 3;

    private const MISSING_FILL = 'FF0000';

    private const HEADER_FILL = 'DAE3F3';

    private const DATE_FORMAT = '[$-10000]d\ mmm\ yyyy;@';

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, PayrollPeriod $period): array
    {
        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('payroll_category', PayrollCategory::Crew)
            ->with([
                'employee.position:id,title',
                'employee.project:id,title',
            ])
            ->get()
            ->sortBy([
                fn (PayrollRecord $record) => mb_strtolower((string) ($record->employee?->name ?? '')),
                fn (PayrollRecord $record) => (string) ($record->employee?->employee_no ?? ''),
            ])
            ->values();

        $employeeIds = $records->pluck('employee_id')->all();

        /** @var Collection<int, CrewTimesheet> $timesheetsByEmployee */
        $timesheetsByEmployee = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        /** @var Collection<int, EmployeeDeployment> $latestDeploymentsByEmployee */
        $latestDeploymentsByEmployee = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->with('client:id,name')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        $this->writeSummaryRow($sheet);
        $this->writeHeaderRow($sheet);

        $rowNumber = self::DATA_START_ROW;
        $serialNumber = 1;
        $missingCoordinates = [];

        foreach ($records as $record) {
            /** @var Employee $employee */
            $employee = $record->employee;
            $timesheet = $timesheetsByEmployee->get($employee->id);
            $deployment = $latestDeploymentsByEmployee->get($employee->id);

            $missingCoordinates = array_merge(
                $missingCoordinates,
                $this->writeDataRow(
                    $sheet,
                    $rowNumber,
                    $serialNumber,
                    $record,
                    $employee,
                    $timesheet,
                    $deployment,
                ),
            );

            $rowNumber++;
            $serialNumber++;
        }

        $lastDataRow = max($rowNumber - 1, self::DATA_START_ROW - 1);
        $this->applyWorksheetFormatting($sheet, $lastDataRow);
        $this->applyMissingHighlights($sheet, $missingCoordinates);

        $tempPath = tempnam(sys_get_temp_dir(), 'crew-payroll-export-');

        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temporary crew payroll export file.');
        }

        $xlsxPath = $tempPath.'.xlsx';
        rename($tempPath, $xlsxPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);

        $slug = str($period->name ?? 'period-'.$period->id)->slug();

        return [
            'path' => $xlsxPath,
            'filename' => "crew-payroll-{$slug}.xlsx",
        ];
    }

    private function writeSummaryRow(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CODE');
        $sheet->setCellValue('P1', '=SUBTOTAL(9,P'.self::DATA_START_ROW.':P200)');
        $sheet->setCellValue('Q1', '=SUBTOTAL(9,Q'.self::DATA_START_ROW.':Q200)');
        $sheet->setCellValue('R1', '=SUBTOTAL(9,R'.self::DATA_START_ROW.':R200)');
    }

    private function writeHeaderRow(Worksheet $sheet): void
    {
        $headers = [
            'A' => 'S/L NO.',
            'B' => 'EMP.NO.',
            'C' => 'NAME',
            'D' => 'DESIGNATION',
            'E' => 'CLIENT',
            'F' => 'PROJECT',
            'G' => 'STAND BY',
            'H' => null,
            'I' => 'DAYS',
            'J' => 'ON SITE',
            'K' => null,
            'L' => 'DAYS',
            'M' => 'BASIC SALARY ',
            'N' => 'SUPPLIM ALLOW',
            'O' => 'SITE ALLOW',
            'P' => 'STAND BY',
            'Q' => 'ON SITE',
            'R' => 'ADD / DED',
            'S' => 'TOTAL SALARY',
            'T' => 'PAYMENT METHOD',
        ];

        foreach ($headers as $column => $header) {
            if ($header !== null) {
                $sheet->setCellValue("{$column}".self::HEADER_ROW, $header);
            }
        }

        $sheet->mergeCells('G'.self::HEADER_ROW.':H'.self::HEADER_ROW);
        $sheet->mergeCells('J'.self::HEADER_ROW.':K'.self::HEADER_ROW);
    }

    /**
     * @return list<string>
     */
    private function writeDataRow(
        Worksheet $sheet,
        int $rowNumber,
        int $serialNumber,
        PayrollRecord $record,
        Employee $employee,
        ?CrewTimesheet $timesheet,
        ?EmployeeDeployment $deployment,
    ): array {
        $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
        $rates = is_array($breakdown['rates'] ?? null) ? $breakdown['rates'] : [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];

        $standbyPay = $this->toFloat($lines['standby_pay'] ?? null);
        $onsitePay = $this->toFloat($lines['onsite_pay'] ?? null);
        $siteAllowancePay = $this->toFloat($lines['site_allowance'] ?? null);
        $supplementaryPay = $this->toFloat($lines['supplementary_allowance'] ?? null);
        $onsiteTotalPay = round($onsitePay + $siteAllowancePay + $supplementaryPay, 2);

        $bonus = $this->toFloat($record->bonus);
        $deductions = $this->toFloat($record->other_deductions);
        $netAdjustment = round($bonus - $deductions, 2);

        $paymentMethod = $record->salary_payment_method ?? $employee->salary_payment_method;

        $cells = [
            'A' => $this->presentValue($serialNumber, false),
            'B' => $this->presentValue($employee->employee_no, ! filled($employee->employee_no)),
            'C' => $this->presentValue($employee->name, ! filled($employee->name)),
            'D' => $this->presentValue($employee->position?->title, ! filled($employee->position?->title)),
            'E' => $this->presentValue($deployment?->client?->name, ! filled($deployment?->client?->name)),
            'F' => $this->presentValue($employee->project?->title, ! filled($employee->project?->title)),
            'G' => $this->presentDate($timesheet?->standby_from),
            'H' => $this->presentDate($timesheet?->standby_to),
            'I' => $this->presentNumeric($breakdown['standby_days'] ?? null),
            'J' => $this->presentDate($timesheet?->onsite_from),
            'K' => $this->presentDate($timesheet?->onsite_to),
            'L' => $this->presentNumeric($breakdown['onsite_days'] ?? null),
            'M' => $this->presentNumeric($rates['basic_daily'] ?? null),
            'N' => $this->presentNumeric($rates['supplementary_allowance_daily'] ?? null),
            'O' => $this->presentNumeric($rates['site_allowance_daily'] ?? null),
            'P' => $this->presentNumeric($standbyPay, false),
            'Q' => $this->presentNumeric($onsiteTotalPay, false),
            'R' => $this->presentAdjustment($netAdjustment),
            'S' => $this->presentNumeric($record->net_salary, false),
            'T' => $this->presentValue(
                $paymentMethod instanceof SalaryPaymentMethod ? $paymentMethod->label() : null,
                $paymentMethod === null,
            ),
        ];

        $missingCoordinates = [];

        foreach ($cells as $column => $cell) {
            $coordinate = "{$column}{$rowNumber}";
            $this->writeCell($sheet, $coordinate, $cell, in_array($column, ['G', 'H', 'J', 'K'], true));

            if ($cell['missing']) {
                $missingCoordinates[] = $coordinate;
            }
        }

        return $missingCoordinates;
    }

    /**
     * @return array{value: mixed, missing: bool, is_date: bool}
     */
    private function presentValue(mixed $value, bool $missing): array
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
    private function presentDate(?CarbonInterface $date): array
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
    private function presentNumeric(mixed $value, bool $missingWhenNull = true): array
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
    private function presentAdjustment(float $netAdjustment): array
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
    private function writeCell(Worksheet $sheet, string $coordinate, array $cell, bool $isDateColumn): void
    {
        $value = $cell['value'];

        if ($value === null) {
            $sheet->setCellValue($coordinate, null);
        } elseif ($cell['is_date']) {
            $sheet->setCellValue($coordinate, $value);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        } elseif ($coordinate[0] === 'B' && is_scalar($value)) {
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
    private function applyMissingHighlights(Worksheet $sheet, array $coordinates): void
    {
        foreach ($coordinates as $coordinate) {
            $sheet->getStyle($coordinate)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB(self::MISSING_FILL);
        }
    }

    private function applyWorksheetFormatting(Worksheet $sheet, int $lastDataRow): void
    {
        $headerStyle = [
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
        ];

        $sheet->getStyle('A'.self::HEADER_ROW.':T'.self::HEADER_ROW)->applyFromArray($headerStyle);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);

        $columnWidths = [
            'A' => 8,
            'B' => 12,
            'C' => 28,
            'D' => 22,
            'E' => 18,
            'F' => 16,
            'G' => 14,
            'H' => 14,
            'I' => 8,
            'J' => 14,
            'K' => 14,
            'L' => 8,
            'M' => 14,
            'N' => 14,
            'O' => 14,
            'P' => 12,
            'Q' => 12,
            'R' => 12,
            'S' => 14,
            'T' => 18,
        ];

        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($lastDataRow < self::DATA_START_ROW) {
            return;
        }

        $dataRange = 'A'.self::DATA_START_ROW.":T{$lastDataRow}";

        $sheet->getStyle($dataRange)->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ]);

        $sheet->getStyle('I'.self::DATA_START_ROW.":I{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        $sheet->getStyle('L'.self::DATA_START_ROW.":L{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        $sheet->getStyle('P'.self::DATA_START_ROW.":P{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
        ]);

        $sheet->getStyle('Q'.self::DATA_START_ROW.":Q{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        foreach (['G', 'H', 'J', 'K'] as $dateColumn) {
            $sheet->getStyle("{$dateColumn}".self::DATA_START_ROW.":{$dateColumn}{$lastDataRow}")
                ->getNumberFormat()
                ->setFormatCode(self::DATE_FORMAT);
        }

        $sheet->getStyle('M'.self::DATA_START_ROW.":S{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }
}
