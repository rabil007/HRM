<?php

namespace App\Support\Payroll\Wps;

use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Support\Collection;

final class WpsExportValidator
{
    /**
     * @param  Collection<int, PayrollRecord>  $records
     * @return array{
     *     eligible: Collection<int, PayrollRecord>,
     *     skipped: list<array{record_id: int, employee_name: string, employee_no: string|null, reason: string}>
     * }
     */
    public function partition(Company $company, PayrollPeriod $period, Collection $records): array
    {
        $skipped = [];
        $eligible = collect();

        if (! filled($company->wps_mol_uid)) {
            return [
                'eligible' => collect(),
                'skipped' => [[
                    'record_id' => 0,
                    'employee_name' => '—',
                    'employee_no' => null,
                    'reason' => 'Company WPS MOL UID is missing.',
                ]],
            ];
        }

        if (! filled($company->wps_agent_code)) {
            return [
                'eligible' => collect(),
                'skipped' => [[
                    'record_id' => 0,
                    'employee_name' => '—',
                    'employee_no' => null,
                    'reason' => 'Company WPS agent code is missing.',
                ]],
            ];
        }

        foreach ($records as $record) {
            $record->loadMissing([
                'employee.currentContract',
                'employee.contracts',
                'employee.primaryBankAccount.bank',
            ]);
            $employee = $record->employee;
            $reason = $this->skipReason($record);

            if ($reason !== null) {
                $skipped[] = [
                    'record_id' => $record->id,
                    'employee_name' => (string) ($employee?->name ?? '—'),
                    'employee_no' => $employee?->employee_no,
                    'reason' => $reason,
                ];

                continue;
            }

            $eligible->push($record);
        }

        return compact('eligible', 'skipped');
    }

    private function skipReason(PayrollRecord $record): ?string
    {
        if (! in_array($record->status, ['approved', 'paid'], true)) {
            return 'Payroll record must be approved or paid.';
        }

        $employee = $record->employee;

        if (! filled(WpsLaborIdentifier::forPayrollRecord($record))) {
            return 'Labor contract ID is missing.';
        }

        $bankAccount = $employee?->primaryBankAccount;

        if ($bankAccount === null) {
            return 'Primary bank account is missing.';
        }

        if (! filled($bankAccount->iban)) {
            return 'IBAN is missing.';
        }

        if (! filled($bankAccount->bank?->uae_routing_code_agent_id)) {
            return 'Bank routing code is missing.';
        }

        return null;
    }
}
