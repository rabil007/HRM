<?php

namespace App\Exports;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class CompaniesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Company>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Slug',
            'Industry',
            'Country',
            'Currency',
            'City',
            'Address',
            'Phone',
            'Email',
            'Website',
            'Company Size',
            'Registration Number',
            'Tax ID',
            'Timezone',
            'Payroll Cycle',
            'Working Days',
            'WPS Agent Code',
            'WPS MOL UID',
            'Status',
            'Created At',
        ];
    }

    public function map($company): array
    {
        return [
            $company->id,
            $company->name,
            $company->slug,
            $company->industry,
            $company->country?->code,
            $company->currency?->code,
            $company->city,
            $company->address,
            $company->phone,
            $company->email,
            $company->website,
            $company->company_size,
            $company->registration_number,
            $company->tax_id,
            $company->timezone,
            $company->payroll_cycle,
            is_array($company->working_days) ? implode(',', $company->working_days) : null,
            $company->wps_agent_code,
            $company->wps_mol_uid,
            $company->status,
            optional($company->created_at)->toDateTimeString(),
        ];
    }
}
