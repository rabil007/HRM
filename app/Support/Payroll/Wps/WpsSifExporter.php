<?php

namespace App\Support\Payroll\Wps;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WpsSifExporter
{
    /**
     * @param  Collection<int, PayrollRecord>  $records
     */
    public function export(Company $company, PayrollPeriod $period, Collection $records, string $reference): string
    {
        $now = CarbonImmutable::now($company->timezone ?? config('app.timezone'));
        $salaryMonth = $period->end_date?->format('mY') ?? $now->format('mY');
        $totalSalary = number_format($records->sum(fn (PayrollRecord $record) => (float) $record->net_salary), 2, '.', '');
        $employerId = (string) ($company->wps_mol_uid ?? '');
        $routingCode = (string) ($company->wps_agent_code ?? '');

        $lines = [
            implode(',', [
                'SCR',
                $employerId,
                $routingCode,
                $now->format('dmY'),
                $now->format('Hi'),
                $salaryMonth,
                (string) $records->count(),
                $totalSalary,
                'AED',
                $reference,
            ]),
        ];

        foreach ($records as $record) {
            $record->loadMissing([
                'employee.primaryBankAccount.bank',
            ]);

            $employee = $record->employee;
            $bankAccount = $employee?->primaryBankAccount;
            $fixedIncome = $this->fixedIncome($record);
            $variableIncome = max((float) $record->net_salary - $fixedIncome, 0);

            $lines[] = implode(',', [
                'EDR',
                (string) ($employee?->labor_card_number ?? ''),
                (string) ($bankAccount?->bank?->uae_routing_code_agent_id ?? ''),
                (string) ($bankAccount?->iban ?? ''),
                $period->start_date?->format('dmY') ?? '',
                $period->end_date?->format('dmY') ?? '',
                (string) max((int) ($record->present_days ?: $record->working_days), 0),
                number_format($fixedIncome, 2, '.', ''),
                number_format($variableIncome, 2, '.', ''),
                number_format((float) $record->leave_days, 2, '.', ''),
            ]);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function fixedIncome(PayrollRecord $record): float
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

    public function makeReference(Company $company, PayrollPeriod $period): string
    {
        return Str::upper(Str::slug($company->slug.'-'.$period->id.'-'.now()->format('YmdHis')));
    }
}
