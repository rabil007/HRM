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
        $totalSalary = self::integerAmount(
            $this->records->sum(fn (PayrollRecord $record) => (float) $record->net_salary),
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
            self::compactIban($this->company->wps_employer_iban),
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
            'contract' => fn ($query) => $query->withTrashed(),
        ]);

        $bankAccount = $record->resolvedEmployeeBankAccount();
        $bank = $record->resolvedBank();
        $breakdown = $record->calculation_breakdown ?? [];
        $startDate = ! empty($breakdown['period_start_date'])
            ? CarbonImmutable::parse($breakdown['period_start_date'])
            : $this->period->start_date;
        $endDate = ! empty($breakdown['period_end_date'])
            ? CarbonImmutable::parse($breakdown['period_end_date'])
            : $this->period->end_date;
        $inclusiveDays = ($startDate !== null && $endDate !== null)
            ? (int) $startDate->diffInDays($endDate) + 1
            : 0;

        return [
            'EDR',
            (string) (WpsLaborIdentifier::forPayrollRecord($record) ?? ''),
            (string) ($bank?->uae_routing_code_agent_id ?? $bankAccount?->bank?->uae_routing_code_agent_id ?? ''),
            self::compactIban($bankAccount?->iban),
            $startDate?->format('Y-m-d') ?? '',
            $endDate?->format('Y-m-d') ?? '',
            $inclusiveDays,
            self::integerAmount((float) $record->net_salary),
            0,
            (int) round((float) $record->leave_days),
            (string) ($record->employee?->name ?? ''),
        ];
    }

    public static function formatIbanForDisplay(?string $iban): string
    {
        $clean = self::compactIban($iban);

        if ($clean === '') {
            return '';
        }

        return trim(chunk_split($clean, 4, ' '));
    }

    private static function compactIban(?string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $iban) ?? '');
    }

    private function periodInclusiveDays(): int
    {
        $start = $this->period->start_date;
        $end = $this->period->end_date;

        if ($start === null || $end === null) {
            return 0;
        }

        return $start->diffInDays($end) + 1;
    }

    private static function integerAmount(float|int|string $amount): int
    {
        return (int) round((float) $amount);
    }

    public function fixedIncome(PayrollRecord $record): float
    {
        if ($record->payroll_category === PayrollCategory::Crew) {
            $breakdown = $record->calculation_breakdown ?? [];
            $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];

            return (float) ($lines['total_standby_pay'] ?? 0) + (float) ($lines['onsite_pay'] ?? 0);
        }

        return (float) $record->basic_salary
            + (float) $record->housing_allowance
            + (float) $record->transport_allowance;
    }
}
