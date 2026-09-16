<?php

namespace App\Exports;

use App\Enums\CrewPhaseCode;
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
    public function __construct(
        private readonly Builder $query,
        private readonly bool $includesLegacyColumns = false,
    ) {}

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    public static function forQuery(Builder $query): self
    {
        $includesLegacyColumns = (clone $query)
            ->whereHas('phases', function (Builder $phaseQuery): void {
                $phaseQuery->whereIn('phase_code', [
                    CrewPhaseCode::TravelIn->value,
                    CrewPhaseCode::ReadyToJoin->value,
                ]);
            })
            ->exists();

        return new self($query, $includesLegacyColumns);
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = [
            'Assignment No',
            'Employee No',
            'Employee Name',
            'Rank',
            'Vessel',
            'Client',
            'Status',
            'Current Phase',
            'Source',
            'Planned Arrival',
            'Planned Join',
            'Planned Sign-Off',
            'Planned Travel Home',
            'Actual Arrival',
        ];

        if ($this->includesLegacyColumns) {
            $headings = array_merge($headings, $this->legacyHeadings());
        }

        return array_merge($headings, [
            'P0 From',
            'P0 To',
            'P0 Days',
            'Join Standby Periods',
            'Join Standby Days',
            'Training Periods',
            'Training Days',
            'Training Details',
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
        ]);
    }

    /**
     * @param  CrewAssignment  $assignment
     * @return list<mixed>
     */
    public function map($assignment): array
    {
        $row = CrewMovementHistoryPresenter::toArray($assignment);

        $mapped = [
            $row['assignment_no'],
            $row['employee']['employee_no'],
            $row['employee']['name'],
            $row['rank']['name'] ?? null,
            $row['vessel']['name'] ?? null,
            $row['client']['name'] ?? null,
            $row['status_label'],
            $row['current_phase']['label'] ?? null,
            $row['source_label'],
            $this->date($row['planned_arrival']),
            $this->date($row['planned_join']),
            $this->date($row['planned_signoff']),
            $this->date($row['planned_travel_home']),
            $this->date($row['actual_arrival']),
        ];

        if ($this->includesLegacyColumns) {
            $mapped = array_merge($mapped, $this->legacyValues($row));
        }

        return array_merge($mapped, [
            $this->date($row['pre_mobilisation']['from']),
            $this->end($row['pre_mobilisation']),
            $row['pre_mobilisation']['total_days'],
            $this->periods($row['join_standby']['periods']),
            $row['join_standby']['total_days'],
            $this->periods($row['training']['periods']),
            $row['training']['total_days'],
            implode('; ', $row['training']['details']),
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
        ]);
    }

    /**
     * @return list<string>
     */
    private function legacyHeadings(): array
    {
        return [
            'Legacy Planned Travel In',
            'Legacy Travel In Periods',
            'Legacy Travel In From',
            'Legacy Travel In To/Arrival',
            'Legacy Travel In Days',
            'Legacy Ready To Join Periods',
            'Legacy Ready From',
            'Legacy Ready To',
            'Legacy Ready Days',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function legacyValues(array $row): array
    {
        return [
            $this->date($row['planned_travel_in']),
            $this->periods($row['travel_in']['periods']),
            $this->date($row['travel_in']['from']),
            $this->end($row['travel_in']),
            $row['travel_in']['total_days'],
            $this->periods($row['ready_to_join']['periods']),
            $this->date($row['ready_to_join']['from']),
            $this->end($row['ready_to_join']),
            $row['ready_to_join']['total_days'],
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
