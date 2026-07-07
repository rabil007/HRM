<?php

namespace App\Support\Payroll\Services;

use App\Enums\PayrollCategory;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Services\Concerns\SpreadsheetPayrollExportFormatting;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class CrewPayrollSalarySheetExporter
{
    use SpreadsheetPayrollExportFormatting;

    public const SHEET_NAME = 'Salary Sheet';

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
                'employee.client:id,name',
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

            $missingCoordinates = array_merge(
                $missingCoordinates,
                $this->writeDataRow(
                    $sheet,
                    $rowNumber,
                    $serialNumber,
                    $record,
                    $employee,
                    $timesheet,
                ),
            );

            $rowNumber++;
            $serialNumber++;
        }

        $lastDataRow = max($rowNumber - 1, self::DATA_START_ROW - 1);
        $this->applyWorksheetFormatting($sheet, $lastDataRow);
        $this->applyMissingHighlights($sheet, $missingCoordinates);

        return $this->saveSpreadsheet($spreadsheet, 'crew-payroll-', $period);
    }

    private function writeSummaryRow(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CODE');
        $sheet->setCellValue('P1', '=SUBTOTAL(9,P'.self::DATA_START_ROW.':P200)');
        $sheet->setCellValue('Q1', '=SUBTOTAL(9,Q'.self::DATA_START_ROW.':Q200)');
        $sheet->setCellValue('R1', '=SUBTOTAL(9,R'.self::DATA_START_ROW.':R200)');
        $sheet->setCellValue('S1', '=SUBTOTAL(9,S'.self::DATA_START_ROW.':S200)');
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
            'S' => 'OT',
            'T' => 'TOTAL SALARY',
            'U' => 'PAYMENT METHOD',
            'V' => 'SALARY STRUCTURE',
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
    ): array {
        $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
        $rates = is_array($breakdown['rates'] ?? null) ? $breakdown['rates'] : [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];

        $standbyPay = $this->toFloat($lines['standby_pay'] ?? null);
        $onsitePay = $this->toFloat($lines['onsite_pay'] ?? null);
        $siteAllowancePay = $this->toFloat($lines['site_allowance'] ?? null);
        $supplementaryPay = $this->toFloat($lines['supplementary_allowance'] ?? null);
        $onsiteTotalPay = round($onsitePay + $siteAllowancePay + $supplementaryPay, 2);
        $overtimePay = $this->toFloat($lines['overtime'] ?? $record->overtime_pay ?? null);

        $bonus = $this->toFloat($record->bonus);
        $deductions = $this->toFloat($record->other_deductions);
        $netAdjustment = round($bonus - $deductions, 2);

        $paymentMethod = $record->salary_payment_method ?? $employee->salary_payment_method;
        $salaryStructure = $this->resolveSalaryStructureLabel($breakdown);

        $cells = [
            'A' => $this->presentValue($serialNumber, false),
            'B' => $this->presentValue($employee->employee_no, ! filled($employee->employee_no)),
            'C' => $this->presentValue($employee->name, ! filled($employee->name)),
            'D' => $this->presentValue($employee->position?->title, ! filled($employee->position?->title)),
            'E' => $this->presentValue($employee->client?->name, ! filled($employee->client?->name)),
            'F' => $this->presentValue($employee->project?->title, ! filled($employee->project?->title)),
            'G' => $this->presentDate($timesheet?->standby_from),
            'H' => $this->presentDate($timesheet?->standby_to),
            'I' => $this->presentNumeric($timesheet?->standby_days ?? $breakdown['standby_days'] ?? null),
            'J' => $this->presentDate($timesheet?->onsite_from),
            'K' => $this->presentDate($timesheet?->onsite_to),
            'L' => $this->presentNumeric($timesheet?->onsite_days ?? $breakdown['onsite_days'] ?? null),
            'M' => $this->presentNumeric($rates['basic_daily'] ?? null),
            'N' => $this->presentNumeric($rates['supplementary_allowance_daily'] ?? null),
            'O' => $this->presentNumeric($rates['site_allowance_daily'] ?? null),
            'P' => $this->presentNumeric($standbyPay, false),
            'Q' => $this->presentNumeric($onsiteTotalPay, false),
            'R' => $this->presentAdjustment($netAdjustment),
            'S' => $this->presentNumeric($overtimePay, false),
            'T' => $this->presentNumeric($record->net_salary, false),
            'U' => $this->presentValue(
                $paymentMethod instanceof SalaryPaymentMethod ? $paymentMethod->label() : null,
                $paymentMethod === null,
            ),
            'V' => $this->presentValue($salaryStructure, false),
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

    private function applyWorksheetFormatting(Worksheet $sheet, int $lastDataRow): void
    {
        $this->applyHeaderStyle($sheet, 'V');
        $this->applyColumnWidths($sheet, [
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
            'S' => 12,
            'T' => 14,
            'U' => 18,
            'V' => 16,
        ]);
        $this->applyDataBorderStyle($sheet, 'V', $lastDataRow);
        $this->applyAutoFilter($sheet, 'V', $lastDataRow);

        if ($lastDataRow < self::DATA_START_ROW) {
            return;
        }

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

        $this->applyDateFormatRange($sheet, 'G'.self::DATA_START_ROW.':H'.$lastDataRow);
        $this->applyDateFormatRange($sheet, 'J'.self::DATA_START_ROW.':K'.$lastDataRow);
        $this->applyDaysFormat($sheet, 'I'.self::DATA_START_ROW.':I'.$lastDataRow);
        $this->applyDaysFormat($sheet, 'L'.self::DATA_START_ROW.':L'.$lastDataRow);
        $this->applyMoneyFormat($sheet, 'M'.self::DATA_START_ROW.':T'.$lastDataRow);
    }

    /**
     * @param  array<string, mixed>  $breakdown
     */
    private function resolveSalaryStructureLabel(array $breakdown): string
    {
        return ($breakdown['salary_structure'] ?? 'daily') === 'monthly'
            ? 'Monthly'
            : 'Daily';
    }
}
