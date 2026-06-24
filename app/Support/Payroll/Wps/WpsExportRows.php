<?php

namespace App\Support\Payroll\Wps;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WpsExportRows
{
    /**
     * @param  Collection<int, PayrollRecord>  $records
     */
    public function __construct(
        private readonly Company $company,
        private readonly PayrollPeriod $period,
        private readonly Collection $records,
        private readonly string $reference,
    ) {}

    /**
     * @return list<list<string|int|float>>
     */
    public function edrRowsForExcel(): array
    {
        return $this->records
            ->map(fn (PayrollRecord $record) => $this->edrRowForExcel($record))
            ->values()
            ->all();
    }

    /**
     * @return list<string|int|float>
     */
    public function scrRowForExcel(): array
    {
        $now = CarbonImmutable::now($this->company->timezone ?? config('app.timezone'));
        $salaryMonth = $this->period->end_date?->format('mY') ?? $now->format('mY');
        $totalSalary = number_format(
            $this->records->sum(fn (PayrollRecord $record) => (float) $record->net_salary),
            2,
            '.',
            '',
        );

        return [
            'SCR',
            (string) ($this->company->wps_mol_uid ?? ''),
            (string) ($this->company->wps_agent_code ?? ''),
            $now->format('Y-m-d'),
            $now->format('Hi'),
            $salaryMonth,
            $this->records->count(),
            $totalSalary,
            'AED',
            '/',
        ];
    }

    /**
     * @return list<string|int|float>
     */
    private function edrRowForExcel(PayrollRecord $record): array
    {
        $record->loadMissing([
            'employee.currentContract',
            'employee.contracts',
            'employee.primaryBankAccount.bank',
        ]);

        $bankAccount = $record->employee?->primaryBankAccount;
        $netSalary = number_format((float) $record->net_salary, 2, '.', '');
        $days = max((int) ($record->present_days ?: $record->working_days), 0);

        return [
            'EDR',
            (string) (WpsLaborIdentifier::forPayrollRecord($record) ?? ''),
            (string) ($bankAccount?->bank?->uae_routing_code_agent_id ?? ''),
            self::formatIbanForDisplay($bankAccount?->iban),
            $this->period->start_date?->format('Y-m-d') ?? '',
            $this->period->end_date?->format('Y-m-d') ?? '',
            $days,
            $netSalary,
            '0.00',
            number_format((float) $record->leave_days, 0, '.', ''),
        ];
    }

    public static function formatIbanForDisplay(?string $iban): string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', (string) $iban) ?? '');

        if ($clean === '') {
            return '';
        }

        return trim(chunk_split($clean, 4, ' '));
    }

    public function fixedIncome(PayrollRecord $record): float
    {
        if ($record->payroll_category === PayrollCategory::Crew) {
            $breakdown = $record->calculation_breakdown ?? [];
            $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];

            return (float) ($lines['standby_pay'] ?? 0) + (float) ($lines['onsite_pay'] ?? 0);
        }

        return (float) $record->basic_salary
            + (float) $record->housing_allowance
            + (float) $record->transport_allowance;
    }
}
