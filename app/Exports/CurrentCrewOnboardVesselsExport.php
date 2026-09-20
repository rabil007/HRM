<?php

namespace App\Exports;

use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewReliefVisibility;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class CurrentCrewOnboardVesselsExport implements FromCollection, WithHeadings, WithMapping, WithStrictNullComparison
{
    /** @var list<int>|null */
    private readonly ?array $authorizedReliefEmployeeIds;

    /**
     * @param  Collection<int, CrewAssignment>  $assignments
     */
    public function __construct(
        private readonly Collection $assignments,
        private readonly ?User $user = null,
        private readonly ?int $companyId = null,
    ) {
        $this->authorizedReliefEmployeeIds = $companyId !== null
            ? CrewReliefVisibility::authorizedReliefEmployeeIds($assignments, $user, $companyId)
            : null;
    }

    public function collection(): Collection
    {
        return $this->assignments;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Vessel',
            'Client',
            'Employee Name',
            'Employee Number',
            'Rank',
            'Assignment Number',
            'Actual Vessel Join',
            'Days Onboard',
            'Planned Sign-Off',
            'Tour of Duty Days',
            'Tour Status',
            'Relief Status',
            'Reliever',
        ];
    }

    /**
     * @param  CrewAssignment  $assignment
     * @return list<mixed>
     */
    public function map($assignment): array
    {
        $row = CrewAssignmentPresenter::listItem(
            $assignment,
            $this->user,
            $this->authorizedReliefEmployeeIds,
        );

        return [
            $row['vessel']['name'] ?? null,
            $row['client']['name'] ?? null,
            $row['employee']['name'] ?? null,
            $row['employee']['employee_no'] ?? null,
            $row['rank']['name'] ?? null,
            $row['assignment_no'],
            $row['actual_join_at'] ?? null,
            $row['days_onboard'] ?? null,
            $row['planned_signoff_at'] ?? null,
            $row['tour_of_duty_days'] ?? null,
            $row['tour_status_label'] ?? null,
            $row['relief_status_label'] ?? null,
            $row['relief_employee']['name'] ?? null,
        ];
    }
}
