<?php

namespace App\Exports;

use App\Models\CrewAssignment;
use App\Support\Reports\CrewMovementHistoryPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class CrewMovementHistoryExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<CrewAssignment>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Assignment No',
            'Employee No',
            'Employee Name',
            'Rank',
            'Vessel',
            'Client',
            'Sponsor / Visa Type',
            'Status',
            'Current Phase',
            'Source',
            'Planned Travel In',
            'Planned Join',
            'Planned Sign-Off',
            'Planned Travel Home',
            'P0 From',
            'P0 To',
            'P0 Days',
            'P1 From',
            'Arrival Date',
            'P1 Days',
            'Join Standby Periods',
            'Join Standby Days',
            'Training Periods',
            'Training Days',
            'Training Details',
            'Ready Periods',
            'Ready From',
            'Ready To',
            'Ready Days',
            'On-Vessel Periods',
            'Actual Join',
            'Actual Disembarkation',
            'Vessel Days',
            'Demob Standby Periods',
            'Demob Standby From',
            'Demob Standby To',
            'Demob Standby Days',
            'Home / Redeploy Periods',
            'Home / Redeploy From',
            'Home / Redeploy To',
            'Home / Redeploy Days',
            'Assignment Started',
            'Assignment Closed',
            'Total Assignment Days',
            'Remarks',
            'Needs Attention',
            'Warnings',
            'Has Corrections',
            'Correction Count',
            'Last Corrected At',
        ];
    }

    /**
     * @param  CrewAssignment  $assignment
     * @return list<mixed>
     */
    public function map($assignment): array
    {
        $row = CrewMovementHistoryPresenter::toArray($assignment);

        return [
            $row['assignment_no'],
            $row['employee']['employee_no'],
            $row['employee']['name'],
            $row['rank']['name'] ?? null,
            $row['vessel']['name'] ?? null,
            $row['client']['name'] ?? null,
            $row['visa_type']['name'] ?? null,
            $row['status_label'],
            $row['current_phase']['label'] ?? null,
            $row['source_label'],
            $this->date($row['planned_travel_in']),
            $this->date($row['planned_join']),
            $this->date($row['planned_signoff']),
            $this->date($row['planned_travel_home']),
            $this->date($row['pre_mobilisation']['from']),
            $this->end($row['pre_mobilisation']),
            $row['pre_mobilisation']['total_days'],
            $this->date($row['travel_in']['from']),
            $this->end($row['travel_in']),
            $row['travel_in']['total_days'],
            $this->periods($row['join_standby']['periods']),
            $row['join_standby']['total_days'],
            $this->periods($row['training']['periods']),
            $row['training']['total_days'],
            implode('; ', $row['training']['details']),
            $this->periods($row['ready_to_join']['periods']),
            $this->date($row['ready_to_join']['from']),
            $this->end($row['ready_to_join']),
            $row['ready_to_join']['total_days'],
            $this->periods($row['on_vessel']['periods']),
            $this->date($row['on_vessel']['actual_join']),
            $this->end($row['on_vessel']),
            $row['on_vessel']['total_days'],
            $this->periods($row['demob_standby']['periods']),
            $this->date($row['demob_standby']['from']),
            $this->end($row['demob_standby']),
            $row['demob_standby']['total_days'],
            $this->periods($row['home_redeploy']['periods']),
            $this->date($row['home_redeploy']['from']),
            $this->end($row['home_redeploy']),
            $row['home_redeploy']['total_days'],
            $this->date($row['assignment_started']),
            $this->date($row['assignment_closed']),
            $row['total_assignment_days'],
            $row['remarks'],
            $row['needs_attention'] ? 'Yes' : 'No',
            implode('; ', $row['warnings']),
            ($row['has_corrections'] ?? false) ? 'Yes' : 'No',
            $row['correction_count'] ?? 0,
            $this->date($row['last_corrected_at'] ?? null),
        ];
    }

    /**
     * @param  list<array{start: string|null, end: string|null, status: string}>  $periods
     */
    private function periods(array $periods): string
    {
        return collect($periods)
            ->map(fn (array $period): string => $this->date($period['start']).' → '.(
                $period['status'] === 'active' ? 'Ongoing' : $this->date($period['end'])
            ))
            ->implode('; ');
    }

    /**
     * @param  array{to?: string|null, periods: list<array{status: string}>}  $summary
     */
    private function end(array $summary): string
    {
        return collect($summary['periods'])->contains('status', 'active')
            ? 'Ongoing'
            : $this->date($summary['to'] ?? null);
    }

    private function date(?string $date): string
    {
        return $date === null ? 'Not recorded' : CarbonImmutable::parse($date)->format('d M Y');
    }
}
