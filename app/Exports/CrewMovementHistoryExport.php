<?php

namespace App\Exports;

use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Support\Reports\CrewMovementHistoryPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
            'Remarks',
            'Planned Arrival',
            'Planned Join',
            'Planned Sign-Off',
            'Planned Sign-Off Source',
            'Tour of Duty Days',
            'Planned Sign-Off Override Reason',
            'Planned Travel Home',
            'Actual Arrival Date/Time',
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
            'Training History',
            'Training Details',
            'Training Provider',
            'Training Course',
            'Training Employee Linked',
            'On-Vessel Periods',
            'Actual Join Date/Time',
            'Actual Disembarkation Date/Time',
            'Vessel Days',
            'Demob Standby Periods',
            'Demob Standby From',
            'Demob Standby To',
            'Demob Standby Days',
            'Home / Redeploy Periods',
            'Actual Return Home Date/Time',
            'Home / Redeploy From',
            'Home / Redeploy To',
            'Home / Redeploy Days',
            'Phase Timeline',
            'Pre-Join Accommodation',
            'Pre-Join Hotel',
            'Pre-Join Room Type',
            'Pre-Join Check-In',
            'Pre-Join Check-Out',
            'Post-Sign-Off Accommodation',
            'Post-Sign-Off Hotel',
            'Post-Sign-Off Room Type',
            'Post-Sign-Off Check-In',
            'Post-Sign-Off Check-Out',
            'Accommodation History',
            'Previous Assignment',
            'Previous Vessel',
            'Movement Relationship',
            'Starting Checkpoint',
            'Next Assignment(s)',
            'Next Vessel(s)',
            'Days Onboard',
            'Remaining Tour Days',
            'Tour Status',
            'Assignment Started Date/Time',
            'Assignment Closed Date/Time',
            'Total Assignment Days',
            'Sign-On Standby Days',
            'On Vessel Days',
            'Sign-Off Standby Days',
            'Total Movement Calendar Days',
            'Needs Attention',
            'Warnings',
            'Has Corrections',
            'Correction Count',
            'Last Corrected At',
            'Pending Correction',
        ]);
    }

    /**
     * @param  CrewAssignment  $assignment
     * @return list<mixed>
     */
    public function map($assignment): array
    {
        $row = CrewMovementHistoryPresenter::toArray($assignment);
        $tour = $row['tour'] ?? [];
        $linked = $row['linked_assignments'] ?? ['previous' => null, 'next' => []];
        $stays = collect($row['accommodation_stays'] ?? []);
        $preJoin = $stays->where('stay_type', 'pre_join')->values();
        $postSignoff = $stays->where('stay_type', 'post_signoff')->values();
        $trainingHistory = $row['training']['history'] ?? [];

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
            $row['remarks'],
            $this->date($row['planned_arrival']),
            $this->date($row['planned_join']),
            $this->date($row['planned_signoff']),
            $tour['planned_signoff_source_label'] ?? $row['planned_signoff_origin_label'] ?? null,
            $tour['tour_of_duty_days'] ?? null,
            $tour['planned_signoff_override_reason'] ?? null,
            $this->date($row['planned_travel_home']),
            $this->dateTime($row['actual_arrival_at'] ?? null),
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
            $this->trainingHistory($trainingHistory),
            implode('; ', $row['training']['details']),
            collect($trainingHistory)->pluck('provider')->filter()->implode('; '),
            collect($trainingHistory)->pluck('course')->filter()->implode('; '),
            collect($trainingHistory)
                ->map(fn (array $item): string => ($item['employee_training_linked'] ?? false) ? 'Yes' : 'No')
                ->implode('; '),
            $this->periods($row['on_vessel']['periods']),
            $this->dateTime($row['on_vessel']['actual_join_at'] ?? null),
            $this->dateTime($row['on_vessel']['actual_disembarkation_at'] ?? null),
            $row['on_vessel']['total_days'],
            $this->periods($row['demob_standby']['periods']),
            $this->date($row['demob_standby']['from']),
            $this->end($row['demob_standby']),
            $row['demob_standby']['total_days'],
            $this->periods($row['home_redeploy']['periods']),
            $this->dateTime($row['home_redeploy']['actual_return_home_at'] ?? null),
            $this->date($row['home_redeploy']['from']),
            $this->end($row['home_redeploy']),
            $row['home_redeploy']['total_days'],
            $this->phaseTimeline($row['phase_timeline'] ?? []),
            $this->stayStatuses($preJoin),
            $this->stayField($preJoin, 'hotel_name'),
            $this->stayField($preJoin, 'room_type_name'),
            $this->stayField($preJoin, 'check_in_date', date: true),
            $this->stayField($preJoin, 'check_out_date', date: true),
            $this->stayStatuses($postSignoff),
            $this->stayField($postSignoff, 'hotel_name'),
            $this->stayField($postSignoff, 'room_type_name'),
            $this->stayField($postSignoff, 'check_in_date', date: true),
            $this->stayField($postSignoff, 'check_out_date', date: true),
            $this->accommodationHistory($stays->all()),
            $linked['previous']['assignment_no'] ?? null,
            $linked['previous']['vessel']['name'] ?? null,
            $linked['relationship_label'] ?? null,
            $this->startingCheckpointLabel($row),
            $this->nextAssignments($linked['next'] ?? []),
            collect($linked['next'] ?? [])->pluck('vessel')->pluck('name')->filter()->implode('; '),
            $tour['days_onboard'] ?? null,
            $tour['remaining_tour_days'] ?? null,
            $tour['tour_status_label'] ?? null,
            $this->dateTime($row['assignment_started_at'] ?? null),
            $this->dateTime($row['assignment_closed_at'] ?? null),
            $row['total_assignment_days'],
            $row['payroll_days']['sign_on_standby']['total_days'] ?? 0,
            $row['payroll_days']['onsite']['total_days'] ?? 0,
            $row['payroll_days']['sign_off_standby']['total_days'] ?? 0,
            $row['payroll_days']['total_days'] ?? 0,
            $row['needs_attention'] ? 'Yes' : 'No',
            implode('; ', $row['warnings']),
            ($row['has_corrections'] ?? false) ? 'Yes' : 'No',
            $row['correction_count'] ?? 0,
            $this->date($row['last_corrected_at'] ?? null),
            ($row['has_pending_corrections'] ?? false) ? 'Yes' : 'No',
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
     * @param  list<array{start?: string|null, start_at?: string|null, end?: string|null, end_at?: string|null, status: string}>  $periods
     */
    private function periods(array $periods): string
    {
        return collect($periods)
            ->map(function (array $period): string {
                $start = $this->dateTime($period['start_at'] ?? null);
                if ($start === 'Not recorded') {
                    $start = $this->date($period['start'] ?? null);
                }

                $end = $period['status'] === 'active'
                    ? 'Ongoing'
                    : $this->dateTime($period['end_at'] ?? null);

                if ($end === 'Not recorded') {
                    $end = $this->date($period['end'] ?? null);
                }

                return $start.' → '.$end;
            })
            ->implode('; ');
    }

    /**
     * @param  list<array<string, mixed>>  $timeline
     */
    private function phaseTimeline(array $timeline): string
    {
        return collect($timeline)
            ->map(function (array $phase): string {
                $code = strtoupper((string) ($phase['phase_code'] ?? ''));
                $legacyPrefix = ($phase['is_legacy'] ?? false) ? 'Legacy ' : '';
                $header = sprintf(
                    '%s%s #%s [seq %s] %s',
                    $legacyPrefix,
                    $code,
                    $phase['occurrence'] ?? '',
                    $phase['sequence'] ?? '',
                    $this->statusLabel((string) ($phase['status'] ?? '')),
                );

                $lines = [$header];

                $plannedStart = $this->dateTime($phase['planned_start_at'] ?? null);
                $plannedEnd = $this->dateTime($phase['planned_end_at'] ?? null);
                if ($plannedStart !== 'Not recorded' || $plannedEnd !== 'Not recorded') {
                    $lines[] = 'Planned: '.$plannedStart.' → '.$plannedEnd;
                }

                $actualStart = $this->dateTime($phase['actual_start_at'] ?? null);
                $actualEnd = ($phase['status'] ?? null) === 'active'
                    ? 'Ongoing'
                    : $this->dateTime($phase['actual_end_at'] ?? null);
                $lines[] = 'Actual: '.$actualStart.' → '.$actualEnd;

                if (($phase['days'] ?? null) !== null) {
                    $lines[] = 'Days: '.$phase['days'];
                }

                if (! empty($phase['remarks'])) {
                    $lines[] = 'Remarks: '.$phase['remarks'];
                }

                if (is_array($phase['details'] ?? null) && $phase['details'] !== []) {
                    $detailParts = [];
                    foreach ($phase['details'] as $key => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $detailParts[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value));
                    }
                    if ($detailParts !== []) {
                        $lines[] = 'Details: '.implode(', ', $detailParts);
                    }
                }

                return implode("\n", $lines);
            })
            ->implode("\n\n");
    }

    /**
     * @param  list<array<string, mixed>>  $history
     */
    private function trainingHistory(array $history): string
    {
        return collect($history)
            ->map(function (array $entry): string {
                $lines = [
                    'Training #'.($entry['occurrence'] ?? ''),
                ];

                if (! empty($entry['provider'])) {
                    $lines[] = 'Provider: '.$entry['provider'];
                }
                if (! empty($entry['course'])) {
                    $lines[] = 'Course: '.$entry['course'];
                }

                $lines[] = 'Planned: '.$this->dateTime($entry['planned_start_at'] ?? null)
                    .' → '.$this->dateTime($entry['planned_end_at'] ?? null);
                $lines[] = 'Actual: '.$this->dateTime($entry['actual_start_at'] ?? null)
                    .' → '.(($entry['status'] ?? null) === 'active'
                        ? 'Ongoing'
                        : $this->dateTime($entry['actual_end_at'] ?? null));
                $lines[] = 'Status: '.$this->statusLabel((string) ($entry['status'] ?? ''));

                $linked = ($entry['employee_training_linked'] ?? false) ? 'Linked' : 'Not linked';
                $courseName = $entry['employee_training']['course_name'] ?? null;
                $lines[] = 'Employee Training: '.$linked.($courseName ? ' · '.$courseName : '');

                if (! empty($entry['remarks'])) {
                    $lines[] = 'Remarks: '.$entry['remarks'];
                }

                return implode("\n", $lines);
            })
            ->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function startingCheckpointLabel(array $row): string
    {
        $code = $row['starting_phase_code'] ?? null;
        $label = $row['starting_phase_label'] ?? null;

        if ($code === null && $label === null) {
            return 'Not recorded';
        }

        return strtoupper((string) $code).($label ? ' · '.$label : '');
    }

    /**
     * @param  list<array<string, mixed>>  $next
     */
    private function nextAssignments(array $next): string
    {
        return collect($next)
            ->map(function (array $linked): string {
                $parts = array_filter([
                    $linked['assignment_no'] ?? null,
                    $linked['source_label'] ?? null,
                    isset($linked['starting_phase_code'], $linked['starting_phase_label'])
                        ? 'Started at '.strtoupper((string) $linked['starting_phase_code']).' · '.$linked['starting_phase_label']
                        : null,
                    isset($linked['current_phase_code'], $linked['current_phase_label'])
                        ? 'Current '.strtoupper((string) $linked['current_phase_code']).' · '.$linked['current_phase_label']
                        : (isset($linked['current_phase_code'])
                            ? 'Current '.strtoupper((string) $linked['current_phase_code'])
                            : null),
                ]);

                return implode(' | ', $parts);
            })
            ->implode('; ');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'planned' => 'Planned',
            'active' => 'Active',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'corrected' => 'Corrected',
            default => $status === '' ? 'Unknown' : str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $stays
     */
    private function stayStatuses($stays): string
    {
        return $stays
            ->map(fn (array $stay): string => (string) ($stay['accommodation_status_label'] ?? $stay['accommodation_status'] ?? ''))
            ->filter()
            ->implode('; ');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $stays
     */
    private function stayField($stays, string $key, bool $date = false): string
    {
        return $stays
            ->map(function (array $stay) use ($key, $date): string {
                $value = $stay[$key] ?? null;

                if ($value === null || $value === '') {
                    return '';
                }

                return $date ? $this->date((string) $value) : (string) $value;
            })
            ->filter()
            ->implode('; ');
    }

    /**
     * @param  list<array<string, mixed>>  $stays
     */
    private function accommodationHistory(array $stays): string
    {
        return collect($stays)
            ->map(function (array $stay): string {
                $parts = array_filter([
                    $stay['stay_type_label'] ?? null,
                    $stay['accommodation_status_label'] ?? null,
                    $stay['hotel_name'] ?? null,
                    isset($stay['check_in_date']) ? 'in '.$this->date($stay['check_in_date']) : null,
                    isset($stay['check_out_date']) ? 'out '.$this->date($stay['check_out_date']) : null,
                ]);

                return implode(' · ', $parts);
            })
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
        return $date === null || $date === '' ? 'Not recorded' : CarbonImmutable::parse($date)->format('d M Y');
    }

    private function dateTime(?string $dateTime): string
    {
        if ($dateTime === null || $dateTime === '') {
            return 'Not recorded';
        }

        $parsed = CarbonImmutable::parse($dateTime);

        if ($parsed->format('H:i:s') === '00:00:00' && ! str_contains($dateTime, ' ')) {
            return $parsed->format('d M Y');
        }

        return $parsed->format('d M Y h:i A');
    }
}
