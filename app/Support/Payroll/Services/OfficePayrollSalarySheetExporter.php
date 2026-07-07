<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Enums\SalaryPaymentMethod;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Services\Concerns\SpreadsheetPayrollExportFormatting;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class OfficePayrollSalarySheetExporter
{
    use SpreadsheetPayrollExportFormatting;

    public const SHEET_NAME = 'Office Payroll';

    /**
     * @return array{path: string, filename: string}
     */
    public function export(int $companyId, PayrollPeriod $period): array
    {
        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where('payroll_category', PayrollCategory::Office)
            ->with([
                'employee.department.parent:id,name',
                'employee.position:id,title',
            ])
            ->get()
            ->sortBy([
                fn (PayrollRecord $record) => mb_strtolower((string) ($record->employee?->name ?? '')),
                fn (PayrollRecord $record) => (string) ($record->employee?->employee_no ?? ''),
            ])
            ->values();

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

            $missingCoordinates = array_merge(
                $missingCoordinates,
                $this->writeDataRow($sheet, $rowNumber, $serialNumber, $record, $employee, $period),
            );

            $rowNumber++;
            $serialNumber++;
        }

        $lastDataRow = max($rowNumber - 1, self::DATA_START_ROW - 1);
        $this->applyWorksheetFormatting($sheet, $lastDataRow);
        $this->applyMissingHighlights($sheet, $missingCoordinates);

        return $this->saveSpreadsheet($spreadsheet, 'office-payroll-', $period);
    }

    private function writeSummaryRow(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CODE');

        foreach (['I', 'J', 'K', 'L', 'N', 'O'] as $column) {
            $sheet->setCellValue(
                "{$column}1",
                '=SUBTOTAL(9,'.$column.self::DATA_START_ROW.':'.$column.'200)',
            );
        }
    }

    private function writeHeaderRow(Worksheet $sheet): void
    {
        $headers = [
            'A' => 'S/L NO.',
            'B' => 'EMPLOYEE NO.',
            'C' => 'EMPLOYEE NAME',
            'D' => 'DEPARTMENT',
            'E' => 'POSITION',
            'F' => 'START DATE',
            'G' => 'END DATE',
            'H' => 'TOTAL DAYS',
            'I' => 'BASIC',
            'J' => 'HOUSING',
            'K' => 'TRANSPORT',
            'L' => 'OTHER',
            'M' => 'PAYMENT METHOD',
            'N' => 'ADD / DED',
            'O' => 'TOTAL SALARY',
        ];

        foreach ($headers as $column => $header) {
            $sheet->setCellValue("{$column}".self::HEADER_ROW, $header);
        }
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
        PayrollPeriod $period,
    ): array {
        $departmentLabel = $this->exportDepartmentLabel($employee);
        $totalDays = $record->working_days ?? $record->present_days;
        $bonus = $this->toFloat($record->bonus);
        $deductions = $this->toFloat($record->total_deductions);
        $netAdjustment = round($bonus - $deductions, 2);
        $paymentMethod = $record->salary_payment_method ?? $employee->salary_payment_method;

        $cells = [
            'A' => $this->presentValue($serialNumber, false),
            'B' => $this->presentValue($employee->employee_no, ! filled($employee->employee_no)),
            'C' => $this->presentValue($employee->name, ! filled($employee->name)),
            'D' => $this->presentValue($departmentLabel, ! filled($departmentLabel)),
            'E' => $this->presentValue($employee->position?->title, ! filled($employee->position?->title)),
            'F' => $this->presentDate($period->start_date),
            'G' => $this->presentDate($period->end_date),
            'H' => $this->presentNumeric($totalDays),
            'I' => $this->presentNumeric($record->basic_salary, false),
            'J' => $this->presentNumeric($record->housing_allowance, false),
            'K' => $this->presentNumeric($record->transport_allowance, false),
            'L' => $this->presentNumeric($record->other_allowances, false),
            'M' => $this->presentValue(
                $paymentMethod instanceof SalaryPaymentMethod ? $paymentMethod->label() : null,
                $paymentMethod === null,
            ),
            'N' => $this->presentAdjustment($netAdjustment),
            'O' => $this->presentNumeric($record->net_salary, false),
        ];

        $missingCoordinates = [];

        foreach ($cells as $column => $cell) {
            $coordinate = "{$column}{$rowNumber}";
            $this->writeCell($sheet, $coordinate, $cell, in_array($column, ['F', 'G'], true));

            if ($cell['missing']) {
                $missingCoordinates[] = $coordinate;
            }
        }

        return $missingCoordinates;
    }

    private function applyWorksheetFormatting(Worksheet $sheet, int $lastDataRow): void
    {
        $this->applyHeaderStyle($sheet, 'O');
        $this->applyColumnWidths($sheet, [
            'A' => 8,
            'B' => 14,
            'C' => 28,
            'D' => 20,
            'E' => 22,
            'F' => 14,
            'G' => 14,
            'H' => 12,
            'I' => 12,
            'J' => 12,
            'K' => 12,
            'L' => 12,
            'M' => 18,
            'N' => 12,
            'O' => 14,
        ]);
        $this->applyDataBorderStyle($sheet, 'O', $lastDataRow);
        $this->applyAutoFilter($sheet, 'O', $lastDataRow);

        if ($lastDataRow < self::DATA_START_ROW) {
            return;
        }

        $sheet->getStyle('H'.self::DATA_START_ROW.":H{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
        ]);

        $this->applyDateFormatRange($sheet, 'F'.self::DATA_START_ROW.':G'.$lastDataRow);
        $this->applyMoneyFormat($sheet, 'I'.self::DATA_START_ROW.':O'.$lastDataRow);
    }
}
