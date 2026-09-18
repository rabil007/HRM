<?php

namespace App\Exports;

use App\Models\LeaveRequest;
use App\Support\Reports\LeaveReportPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

final class LeaveReportExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function __construct(
        private readonly Builder $query,
        private readonly string $timezone,
    ) {}

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public static function forQuery(Builder $query, string $timezone): self
    {
        return new self($query, $timezone);
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
        return [
            'Employee No',
            'Employee Name',
            'Department',
            'Branch',
            'Leave Type',
            'Leave From',
            'Leave To',
            'Total Days',
            'Status',
            'Submitted At',
            'Decided At',
            'Decided By',
        ];
    }

    /**
     * @param  LeaveRequest  $leaveRequest
     * @return list<mixed>
     */
    public function map($leaveRequest): array
    {
        $row = LeaveReportPresenter::toArray($leaveRequest, $this->timezone);

        return [
            $row['employee']['employee_no'],
            $row['employee']['name'],
            $row['department']['name'] ?? null,
            $row['branch']['name'] ?? null,
            $row['leave_type']['name'] ?? null,
            $this->date($row['start_date']),
            $this->date($row['end_date']),
            $row['total_days'],
            $row['status_label'],
            $this->datetime($row['submitted_at']),
            $this->datetime($row['decided_at']),
            $row['decided_by'],
        ];
    }

    private function date(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->format('d M Y');
    }

    private function datetime(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->timezone($this->timezone)->format('d M Y H:i');
    }
}
