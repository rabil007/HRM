<?php

namespace App\Support\Payroll\Wps;

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
        $rows = new WpsExportRows($company, $period, $records, $reference);
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
                'employee.currentContract',
                'employee.contracts',
                'employee.primaryBankAccount.bank',
            ]);

            $bankAccount = $record->employee?->primaryBankAccount;
            $fixedIncome = $rows->fixedIncome($record);
            $variableIncome = max((float) $record->net_salary - $fixedIncome, 0);
            $iban = strtoupper(preg_replace('/\s+/', '', (string) ($bankAccount?->iban ?? '')) ?? '');

            $breakdown = $record->calculation_breakdown ?? [];
            $startDate = ! empty($breakdown['period_start_date'])
                ? CarbonImmutable::parse($breakdown['period_start_date'])
                : $period->start_date;
            $endDate = ! empty($breakdown['period_end_date'])
                ? CarbonImmutable::parse($breakdown['period_end_date'])
                : $period->end_date;

            $lines[] = implode(',', [
                'EDR',
                (string) (WpsLaborIdentifier::forPayrollRecord($record) ?? ''),
                (string) ($bankAccount?->bank?->uae_routing_code_agent_id ?? ''),
                $iban,
                $startDate?->format('dmY') ?? '',
                $endDate?->format('dmY') ?? '',
                (string) max((int) ($record->present_days ?: $record->working_days), 0),
                number_format($fixedIncome, 2, '.', ''),
                number_format($variableIncome, 2, '.', ''),
                number_format((float) $record->leave_days, 2, '.', ''),
            ]);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function makeReference(Company $company, PayrollPeriod $period): string
    {
        return Str::upper(Str::slug($company->slug.'-'.$period->id.'-'.now()->format('YmdHis')));
    }
}
