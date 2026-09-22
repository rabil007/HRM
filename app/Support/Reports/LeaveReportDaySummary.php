<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
use App\Models\LeaveRequest;
use App\Support\Payroll\CountLeaveDaysInRange;
use Illuminate\Database\Eloquent\Builder;

final class LeaveReportDaySummary
{
    public function __construct(
        private readonly CountLeaveDaysInRange $countLeaveDaysInRange,
    ) {}

    /**
     * @param  Builder<LeaveRequest>  $filtered
     * @return array{
     *     total_leave_days: float,
     *     approved_leave_days: float,
     *     pending_leave_days: float,
     *     annual: array{approved: float, pending: float, total: float},
     *     sick: array{approved: float, pending: float, total: float}
     * }
     */
    public function summarize(Builder $filtered, string $leaveFrom, string $leaveTo): array
    {
        $totals = $this->emptyBuckets();

        if ($leaveFrom === '' && $leaveTo === '') {
            $this->sumStoredDays($filtered, $totals);
        } else {
            $this->sumClippedDays($filtered, $leaveFrom, $leaveTo, $totals);
        }

        return $this->finalize($totals);
    }

    /**
     * @return array{
     *     approved: float,
     *     pending: float,
     *     annual_approved: float,
     *     annual_pending: float,
     *     sick_approved: float,
     *     sick_pending: float
     * }
     */
    private function emptyBuckets(): array
    {
        return [
            'approved' => 0.0,
            'pending' => 0.0,
            'annual_approved' => 0.0,
            'annual_pending' => 0.0,
            'sick_approved' => 0.0,
            'sick_pending' => 0.0,
        ];
    }

    /**
     * @param  Builder<LeaveRequest>  $filtered
     * @param  array<string, float>  $totals
     */
    private function sumStoredDays(Builder $filtered, array &$totals): void
    {
        $rows = (clone $filtered)
            ->leftJoin('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereIn('leave_requests.status', ['approved', 'pending'])
            ->select('leave_requests.status', 'leave_types.category')
            ->selectRaw('SUM(leave_requests.total_days) as days')
            ->groupBy('leave_requests.status', 'leave_types.category')
            ->get();

        foreach ($rows as $row) {
            $this->add($totals, (string) $row->status, $row->category, (float) $row->days);
        }
    }

    /**
     * @param  Builder<LeaveRequest>  $filtered
     * @param  array<string, float>  $totals
     */
    private function sumClippedDays(Builder $filtered, string $leaveFrom, string $leaveTo, array &$totals): void
    {
        (clone $filtered)
            ->leftJoin('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereIn('leave_requests.status', ['approved', 'pending'])
            ->select([
                'leave_requests.id',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.status',
                'leave_types.category',
            ])
            ->reorder()
            ->orderBy('leave_requests.id')
            ->chunkById(200, function ($rows) use (&$totals, $leaveFrom, $leaveTo): void {
                foreach ($rows as $row) {
                    $start = $row->start_date?->toDateString() ?? (string) $row->start_date;
                    $end = $row->end_date?->toDateString() ?? (string) $row->end_date;

                    if ($start === '' || $end === '') {
                        continue;
                    }

                    $rangeStart = $leaveFrom !== '' ? $leaveFrom : $start;
                    $rangeEnd = $leaveTo !== '' ? $leaveTo : $end;
                    $days = $this->countLeaveDaysInRange->count($start, $end, $rangeStart, $rangeEnd);
                    $this->add($totals, (string) $row->status, $row->category, $days);
                }
            }, 'leave_requests.id', 'id');
    }

    /**
     * @param  array<string, float>  $totals
     */
    private function add(array &$totals, string $status, mixed $category, float $days): void
    {
        if ($days === 0.0 || ! in_array($status, ['approved', 'pending'], true)) {
            return;
        }

        $totals[$status] += $days;

        $categoryValue = $category instanceof LeaveTypeCategory
            ? $category->value
            : (is_string($category) ? $category : '');

        if ($categoryValue === LeaveTypeCategory::Annual->value) {
            $totals['annual_'.$status] += $days;
        }

        if ($categoryValue === LeaveTypeCategory::Sick->value) {
            $totals['sick_'.$status] += $days;
        }
    }

    /**
     * @param  array<string, float>  $totals
     * @return array{
     *     total_leave_days: float,
     *     approved_leave_days: float,
     *     pending_leave_days: float,
     *     annual: array{approved: float, pending: float, total: float},
     *     sick: array{approved: float, pending: float, total: float}
     * }
     */
    private function finalize(array $totals): array
    {
        $approved = $this->round($totals['approved']);
        $pending = $this->round($totals['pending']);
        $annualApproved = $this->round($totals['annual_approved']);
        $annualPending = $this->round($totals['annual_pending']);
        $sickApproved = $this->round($totals['sick_approved']);
        $sickPending = $this->round($totals['sick_pending']);

        return [
            'total_leave_days' => $this->round($approved + $pending),
            'approved_leave_days' => $approved,
            'pending_leave_days' => $pending,
            'annual' => [
                'approved' => $annualApproved,
                'pending' => $annualPending,
                'total' => $this->round($annualApproved + $annualPending),
            ],
            'sick' => [
                'approved' => $sickApproved,
                'pending' => $sickPending,
                'total' => $this->round($sickApproved + $sickPending),
            ],
        ];
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
