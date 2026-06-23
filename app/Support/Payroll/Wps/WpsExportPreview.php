<?php

namespace App\Support\Payroll\Wps;

use App\Models\Company;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;

final class WpsExportPreview
{
    public function __construct(
        private readonly WpsExportValidator $validator,
    ) {}

    /**
     * @return array{
     *     period: array{id: int, name: string},
     *     eligible_count: int,
     *     skipped: list<array{record_id: int, employee_name: string, employee_no: string|null, reason: string}>,
     *     company: array{wps_mol_uid: string|null, wps_agent_code: string|null}
     * }
     */
    public function forPeriod(Company $company, PayrollPeriod $period): array
    {
        $records = PayrollRecord::query()
            ->where('company_id', $company->id)
            ->where('period_id', $period->id)
            ->with(['employee.primaryBankAccount.bank'])
            ->orderBy('id')
            ->get();

        $partition = $this->validator->partition($company, $period, $records);

        return [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
            ],
            'eligible_count' => $partition['eligible']->count(),
            'skipped' => $partition['skipped'],
            'company' => [
                'wps_mol_uid' => $company->wps_mol_uid,
                'wps_agent_code' => $company->wps_agent_code,
            ],
        ];
    }
}
