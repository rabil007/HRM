<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class CrewReliefExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly Collection $rows,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Current Crew',
            'Employee No',
            'Rank',
            'Vessel',
            'Client',
            'Joined Date',
            'Days Onboard',
            'Planned Sign-Off',
            'Days to Sign-Off',
            'Relief Crew',
            'Relief Status',
            'Relief Planned Join',
            'Readiness',
            'Next Assignment',
            'Attention',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $employee = $row['employee'] ?? null;
        $reliefEmployee = $row['relief_employee'] ?? null;
        $nextAssignment = $row['next_assignment'] ?? null;

        return [
            $employee !== null ? $employee['name'] : '—',
            $employee !== null ? ($employee['employee_no'] ?? '—') : '—',
            $row['rank']['name'] ?? '—',
            $row['vessel']['name'] ?? '—',
            $row['client']['name'] ?? '—',
            $row['joined_date'] ?? '—',
            $row['days_onboard'] ?? 0,
            $row['planned_signoff_at'] ?? '—',
            $row['days_to_signoff_label'] ?? '—',
            $reliefEmployee !== null ? $reliefEmployee['name'] : '—',
            $row['relief_status'] ?? '—',
            $row['relief_planned_join'] ?? '—',
            $row['readiness_label'] ?? '—',
            $nextAssignment !== null ? $nextAssignment['assignment_no'] : 'None',
            $row['attention']['badge'] ?? '—',
        ];
    }
}
