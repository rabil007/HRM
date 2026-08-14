<?php

namespace App\Support\Payroll\Services;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollWorkPeriodClassification;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Services\Concerns\SpreadsheetPayrollExportFormatting;
use App\Support\Settings\CompanyCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Crew payroll salary sheet workbook exporter.
 *
 * Salary Sheet column map (do not reorder without updating reconciliation consumers):
 * A S/L NO. | B EMP.NO. | C NAME | D DESIGNATION | E CLIENT | F PROJECT
 * G–H STAND BY dates | I STAND BY DAYS | J–K ON SITE dates | L ON SITE DAYS
 * M BASIC SALARY | N SUPPLIM ALLOW | O SITE ALLOW
 * P STAND BY pay | Q ON SITE pay | R ADD / DED | S OT | T TOTAL SALARY
 * U PAYMENT METHOD | V SALARY STRUCTURE
 * W ARREARS / PRIOR — sum of prior-period adjustment amounts from calculation_breakdown
 *   (lines.prior_period_amount or prior_period_lines.amount).
 * Reconciliation: P + Q + R + S + W ≈ T (net), where P/Q are current-period pay lines,
 * R is bonus − deductions, S is overtime, and W is prior-period arrears included in net.
 */
final class CrewPayrollSalarySheetExporter
{
    use SpreadsheetPayrollExportFormatting;

    public const SHEET_NAME = 'Salary Sheet';

    /**
     * Last data column on the Salary Sheet (includes arrears column W).
     */
    public const LAST_COLUMN = 'W';

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
            ->with([
                'segments.assignment.vessel',
                'segments.assignment.client',
                'segments.assignment.rank',
            ])
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

        $this->writeMovementDetailsSheet($spreadsheet, $records, $timesheetsByEmployee, $companyId);

        return $this->saveSpreadsheet($spreadsheet, 'crew-payroll-', $period);
    }

    private function writeSummaryRow(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', 'CODE');
        $sheet->setCellValue('P1', '=SUBTOTAL(9,P'.self::DATA_START_ROW.':P200)');
        $sheet->setCellValue('Q1', '=SUBTOTAL(9,Q'.self::DATA_START_ROW.':Q200)');
        $sheet->setCellValue('R1', '=SUBTOTAL(9,R'.self::DATA_START_ROW.':R200)');
        $sheet->setCellValue('S1', '=SUBTOTAL(9,S'.self::DATA_START_ROW.':S200)');
        $sheet->setCellValue('W1', '=SUBTOTAL(9,W'.self::DATA_START_ROW.':W200)');
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
            'W' => 'ARREARS / PRIOR',
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
        $priorPeriodMeta = is_array($breakdown['prior_period_lines'] ?? null)
            ? $breakdown['prior_period_lines']
            : [];

        $standbyPay = $this->toFloat($lines['total_standby_pay'] ?? null);
        $onsitePay = $this->toFloat($lines['onsite_pay'] ?? null);
        $siteAllowancePay = $this->toFloat($lines['site_allowance'] ?? null);
        $supplementaryPay = $this->toFloat($lines['supplementary_allowance'] ?? null);
        $onsiteTotalPay = round($onsitePay + $siteAllowancePay + $supplementaryPay, 2);
        $overtimePay = $this->toFloat($lines['overtime'] ?? $record->overtime_pay ?? null);
        $arrearsPay = $this->toFloat(
            $lines['prior_period_amount']
            ?? $priorPeriodMeta['amount']
            ?? null,
        );

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
            'G' => $this->presentCategoryBoundary(
                $timesheet?->sign_on_standby_from,
                $timesheet !== null
                    && $timesheet->sign_on_standby_from === null
                    && (float) ($timesheet->sign_on_standby_days ?? 0) > 0
                    && $timesheet->hasMovementSegments(),
            ),
            'H' => $this->presentCategoryBoundary(
                $timesheet?->sign_off_standby_to,
                $timesheet !== null
                    && $timesheet->sign_off_standby_to === null
                    && (float) ($timesheet->sign_off_standby_days ?? 0) > 0
                    && $timesheet->hasMovementSegments(),
            ),
            'I' => $this->presentNumeric($breakdown['total_standby_days'] ?? null),
            'J' => $this->presentCategoryBoundary(
                $timesheet?->onsite_from,
                $timesheet !== null
                    && $timesheet->onsite_from === null
                    && (float) ($timesheet->onsite_days ?? 0) > 0
                    && $timesheet->hasMovementSegments(),
            ),
            'K' => $this->presentCategoryBoundary(
                $timesheet?->onsite_to,
                $timesheet !== null
                    && $timesheet->onsite_to === null
                    && (float) ($timesheet->onsite_days ?? 0) > 0
                    && $timesheet->hasMovementSegments(),
            ),
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
            'W' => $this->presentNumeric($arrearsPay, false),
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
        $this->applyHeaderStyle($sheet, self::LAST_COLUMN);
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
            'W' => 16,
        ]);
        $this->applyDataBorderStyle($sheet, self::LAST_COLUMN, $lastDataRow);
        $this->applyAutoFilter($sheet, self::LAST_COLUMN, $lastDataRow);

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

        $sheet->getStyle('W'.self::DATA_START_ROW.":W{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
        ]);

        $this->applyDateFormatRange($sheet, 'G'.self::DATA_START_ROW.':H'.$lastDataRow);
        $this->applyDateFormatRange($sheet, 'J'.self::DATA_START_ROW.':K'.$lastDataRow);
        $this->applyDaysFormat($sheet, 'I'.self::DATA_START_ROW.':I'.$lastDataRow);
        $this->applyDaysFormat($sheet, 'L'.self::DATA_START_ROW.':L'.$lastDataRow);
        $this->applyMoneyFormat($sheet, 'M'.self::DATA_START_ROW.':T'.$lastDataRow);
        $this->applyMoneyFormat($sheet, 'W'.self::DATA_START_ROW.':W'.$lastDataRow);
    }

    /**
     * @param  Collection<int, PayrollRecord>  $records
     * @param  Collection<int, CrewTimesheet>  $timesheetsByEmployee
     */
    /**
     * @param  Collection<int, PayrollRecord>  $records
     * @param  Collection<int, CrewTimesheet>  $timesheetsByEmployee
     */
    private function writeMovementDetailsSheet(
        Spreadsheet $spreadsheet,
        Collection $records,
        Collection $timesheetsByEmployee,
        int $companyId,
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Movement Details');

        $headers = [
            'A' => 'EMP.NO.',
            'B' => 'NAME',
            'C' => 'ASSIGNMENT',
            'D' => 'VESSEL',
            'E' => 'CLIENT / PROJECT',
            'F' => 'RANK',
            'G' => 'CATEGORY',
            'H' => 'FROM',
            'I' => 'TO',
            'J' => 'DAYS',
            'K' => 'SOURCE',
            'L' => 'PERIOD',
            'M' => 'BASIC RATE',
            'N' => 'SITE RATE',
            'O' => 'SUPP RATE',
            'P' => 'AMOUNT',
            'Q' => 'CURRENCY',
        ];

        foreach ($headers as $column => $header) {
            $sheet->setCellValue("{$column}1", $header);
        }

        $row = 2;

        foreach ($records as $record) {
            $employee = $record->employee;
            $timesheet = $timesheetsByEmployee->get($record->employee_id);
            $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
            /** @var list<array<string, mixed>> $presentationLines */
            $presentationLines = is_array($breakdown['earning_periods'] ?? null)
                ? $breakdown['earning_periods']
                : (is_array($breakdown['presentation_lines'] ?? null)
                    ? $breakdown['presentation_lines']
                    : []);

            if ($presentationLines !== []) {
                $currencyCode = $this->resolveCurrencyCode($record, $companyId);

                foreach ($presentationLines as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $this->writeMovementDetailRow($sheet, $row, [
                        'employee_no' => $employee?->employee_no,
                        'name' => $employee?->name,
                        'assignment' => null,
                        'vessel' => null,
                        'client' => null,
                        'rank' => null,
                        'category' => $this->payCategoryLabel($line['pay_category'] ?? null),
                        'from' => $this->formatExportDate($line['from_date'] ?? null),
                        'to' => $this->formatExportDate($line['to_date'] ?? null),
                        'days' => (float) ($line['days'] ?? 0),
                        'source' => 'Calculated',
                        'period' => $this->periodClassificationLabel($line['period_classification'] ?? null),
                        'basic_rate' => $this->nullableFloat($line['basic_daily_rate'] ?? null),
                        'site_rate' => $this->nullableFloat($line['site_allowance_daily_rate'] ?? null),
                        'supp_rate' => $this->nullableFloat($line['supplementary_allowance_daily_rate'] ?? null),
                        'amount' => $this->nullableFloat($line['amount'] ?? null),
                        'currency' => $currencyCode,
                    ]);
                    $row++;
                }

                continue;
            }

            if ($timesheet === null) {
                continue;
            }

            $currencyCode = $this->resolveCurrencyCode($record, $companyId);

            foreach ($timesheet->segments as $segment) {
                $this->writeMovementDetailRow($sheet, $row, [
                    ...$this->segmentMovementRow($employee, $segment),
                    'currency' => $currencyCode,
                ]);
                $row++;
            }
        }
    }

    /**
     * @param  array{
     *     employee_no: mixed,
     *     name: mixed,
     *     assignment: mixed,
     *     vessel: mixed,
     *     client: mixed,
     *     rank: mixed,
     *     category: mixed,
     *     from: mixed,
     *     to: mixed,
     *     days: mixed,
     *     source: mixed,
     *     period: mixed,
     *     basic_rate: mixed,
     *     site_rate: mixed,
     *     supp_rate: mixed,
     *     amount: mixed,
     *     currency?: mixed
     * }  $values
     */
    private function writeMovementDetailRow(Worksheet $sheet, int $row, array $values): void
    {
        $sheet->setCellValue("A{$row}", $values['employee_no']);
        $sheet->setCellValue("B{$row}", $values['name']);
        $sheet->setCellValue("C{$row}", $values['assignment']);
        $sheet->setCellValue("D{$row}", $values['vessel']);
        $sheet->setCellValue("E{$row}", $values['client']);
        $sheet->setCellValue("F{$row}", $values['rank']);
        $sheet->setCellValue("G{$row}", $values['category']);
        $sheet->setCellValue("H{$row}", $values['from']);
        $sheet->setCellValue("I{$row}", $values['to']);
        $sheet->setCellValue("J{$row}", $values['days']);
        $sheet->setCellValue("K{$row}", $values['source']);
        $sheet->setCellValue("L{$row}", $values['period']);
        $sheet->setCellValue("M{$row}", $values['basic_rate']);
        $sheet->setCellValue("N{$row}", $values['site_rate']);
        $sheet->setCellValue("O{$row}", $values['supp_rate']);
        $sheet->setCellValue("P{$row}", $values['amount']);
        $sheet->setCellValue("Q{$row}", $values['currency'] ?? null);
    }

    /**
     * @return array{
     *     employee_no: mixed,
     *     name: mixed,
     *     assignment: mixed,
     *     vessel: mixed,
     *     client: mixed,
     *     rank: mixed,
     *     category: mixed,
     *     from: mixed,
     *     to: mixed,
     *     days: float,
     *     source: mixed,
     *     period: string,
     *     basic_rate: null,
     *     site_rate: null,
     *     supp_rate: null,
     *     amount: null
     * }
     */
    private function segmentMovementRow(?Employee $employee, CrewTimesheetSegment $segment): array
    {
        $assignment = $segment->assignment;

        return [
            'employee_no' => $employee?->employee_no,
            'name' => $employee?->name,
            'assignment' => $assignment?->assignment_no,
            'vessel' => $assignment?->vessel?->name,
            'client' => $assignment?->client?->name,
            'rank' => $assignment?->rank?->name,
            'category' => $segment->pay_category?->label(),
            'from' => $segment->from_date?->format('d-m-Y'),
            'to' => $segment->to_date?->format('d-m-Y'),
            'days' => (float) $segment->days,
            'source' => $segment->source?->label(),
            'period' => PayrollWorkPeriodClassification::Current->label(),
            'basic_rate' => null,
            'site_rate' => null,
            'supp_rate' => null,
            'amount' => null,
        ];
    }

    private function payCategoryLabel(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $category = CrewTimesheetPayCategory::tryFrom($value);

        return $category?->label() ?? $value;
    }

    private function periodClassificationLabel(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return PayrollWorkPeriodClassification::Current->label();
        }

        $classification = PayrollWorkPeriodClassification::tryFrom($value)
            ?? match ($value) {
                'current_period' => PayrollWorkPeriodClassification::Current,
                'prior_period' => PayrollWorkPeriodClassification::Prior,
                default => null,
            };

        return $classification?->label() ?? $value;
    }

    private function formatExportDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('d-m-Y');
        } catch (\Throwable) {
            return is_string($value) ? $value : null;
        }
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return array{value: mixed, missing: bool}
     */
    private function presentCategoryBoundary(mixed $date, bool $multiplePeriods): array
    {
        if ($multiplePeriods) {
            return $this->presentValue('Multiple', false);
        }

        return $this->presentDate($date);
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

    /**
     * Prefer the currency snapshotted at generation over the live company currency.
     */
    private function resolveCurrencyCode(PayrollRecord $record, int $companyId): string
    {
        $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];

        if (is_string($breakdown['currency_code'] ?? null) && $breakdown['currency_code'] !== '') {
            return (string) $breakdown['currency_code'];
        }

        if (filled($record->currency_code ?? null)) {
            return (string) $record->currency_code;
        }

        return CompanyCurrency::codeForCompany($companyId);
    }
}
