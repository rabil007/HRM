<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class PayrollOverviewSummary
{
    /**
     * @return array{
     *     draft_periods: int,
     *     processing_periods: int,
     *     approved_periods: int,
     *     paid_periods: int,
     *     total_employees_in_payroll: int,
     *     last_paid_period_total: float|null,
     *     last_paid_period_name: string|null,
     *     monthly_trend: list<array{month: string, total: float, count: int}>,
     *     attention_items: list<array{title: string, subtitle: string, type: string, severity: string}>,
     *     salary_breakdown: array{basic: float, allowances: float, deductions: float}|null,
     *     department_costs: list<array{name: string, total: float}>|null,
     *     category_split: list<array{name: string, total: float}>|null,
     * }
     */
    public static function forCompany(int $companyId): array
    {
        $periods = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->withCount('payrollRecords')
            ->get();

        $draftPeriods = $periods->where('status', PayrollPeriodStatus::Draft)->count();
        $processingPeriods = $periods->where('status', PayrollPeriodStatus::Processing)->count();
        $approvedPeriods = $periods->where('status', PayrollPeriodStatus::Approved)->count();
        $paidPeriods = $periods->where('status', PayrollPeriodStatus::Paid)->count();

        // Last paid period net total
        $lastPaidPeriod = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', PayrollPeriodStatus::Paid)
            ->orderByDesc('payment_date')
            ->orderByDesc('end_date')
            ->first();

        $lastPaidTotal = null;
        $lastPaidName = null;

        if ($lastPaidPeriod !== null) {
            $lastPaidTotal = (float) PayrollRecord::query()
                ->where('company_id', $companyId)
                ->where('period_id', $lastPaidPeriod->id)
                ->sum('net_salary');
            $lastPaidName = $lastPaidPeriod->name;
        }

        // Active payroll employees (crew + office)
        $crewCount = PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Crew);
        $officeCount = PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Office);
        $totalEmployees = $crewCount + $officeCount;

        // Monthly payroll trend (last 6 months)
        $monthlyTrend = self::monthlyTrend($companyId);

        // Attention items
        $attentionItems = self::attentionItems($periods, $companyId);

        // Advanced Analytics
        $salaryBreakdown = self::salaryBreakdown($companyId, $lastPaidPeriod);
        $departmentCosts = self::departmentCosts($companyId, $lastPaidPeriod);
        $categorySplit = self::categorySplit($companyId, $lastPaidPeriod);

        return [
            'draft_periods' => $draftPeriods,
            'processing_periods' => $processingPeriods,
            'approved_periods' => $approvedPeriods,
            'paid_periods' => $paidPeriods,
            'total_employees_in_payroll' => $totalEmployees,
            'last_paid_period_total' => $lastPaidTotal,
            'last_paid_period_name' => $lastPaidName,
            'monthly_trend' => $monthlyTrend,
            'attention_items' => $attentionItems,
            'salary_breakdown' => $salaryBreakdown,
            'department_costs' => $departmentCosts,
            'category_split' => $categorySplit,
        ];
    }

    /**
     * @return list<array{month: string, total: float, count: int}>
     */
    private static function monthlyTrend(int $companyId): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $months[] = [
                'month' => $date->format('M Y'),
                'year' => $date->year,
                'month_num' => $date->month,
                'total' => 0.0,
                'count' => 0,
            ];
        }

        $records = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->join('payroll_periods', 'payroll_records.period_id', '=', 'payroll_periods.id')
            ->where('payroll_periods.payment_date', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->selectRaw('YEAR(payroll_periods.payment_date) as yr, MONTH(payroll_periods.payment_date) as mo, SUM(payroll_records.net_salary) as total, COUNT(payroll_records.id) as cnt')
            ->groupByRaw('YEAR(payroll_periods.payment_date), MONTH(payroll_periods.payment_date)')
            ->get();

        foreach ($months as &$month) {
            $match = $records->first(fn ($r) => (int) $r->yr === $month['year'] && (int) $r->mo === $month['month_num']);

            if ($match !== null) {
                $month['total'] = (float) $match->total;
                $month['count'] = (int) $match->cnt;
            }

            unset($month['year'], $month['month_num']);
        }

        return array_values($months);
    }

    /**
     * @param  Collection<int, PayrollPeriod>  $periods
     * @return list<array{title: string, subtitle: string, type: string, severity: string}>
     */
    private static function attentionItems(Collection $periods, int $companyId): array
    {
        $items = [];

        foreach ($periods as $period) {
            if ($period->status === PayrollPeriodStatus::Draft) {
                $items[] = [
                    'title' => $period->name,
                    'subtitle' => 'Draft — ready to generate payroll',
                    'type' => 'draft',
                    'severity' => 'info',
                ];
            } elseif ($period->status === PayrollPeriodStatus::Processing) {
                $items[] = [
                    'title' => $period->name,
                    'subtitle' => 'Awaiting approval',
                    'type' => 'pending_approval',
                    'severity' => 'warning',
                ];
            } elseif ($period->status === PayrollPeriodStatus::Approved) {
                $items[] = [
                    'title' => $period->name,
                    'subtitle' => 'Approved — ready to mark as paid',
                    'type' => 'approved',
                    'severity' => 'info',
                ];
            }
        }

        // Sort: warnings first
        usort($items, fn ($a, $b) => ($a['severity'] === 'warning' ? -1 : 1));

        return array_values(array_slice($items, 0, 8));
    }

    /**
     * @return array{basic: float, allowances: float, deductions: float}|null
     */
    private static function salaryBreakdown(int $companyId, ?PayrollPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $sums = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->selectRaw('SUM(basic_salary) as basic, SUM(housing_allowance + transport_allowance + other_allowances) as allowances, SUM(total_deductions) as deductions')
            ->first();

        if ($sums === null || $sums->basic === null) {
            return null;
        }

        return [
            'basic' => (float) $sums->basic,
            'allowances' => (float) $sums->allowances,
            'deductions' => (float) $sums->deductions,
        ];
    }

    /**
     * @return list<array{name: string, total: float}>|null
     */
    private static function departmentCosts(int $companyId, ?PayrollPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $records = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->where('payroll_records.period_id', $period->id)
            ->join('employees', 'payroll_records.employee_id', '=', 'employees.id')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('COALESCE(departments.name, "Unassigned") as name, SUM(payroll_records.net_salary) as total')
            ->groupByRaw('COALESCE(departments.name, "Unassigned")')
            ->orderByDesc('total')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        return $records->map(fn ($r) => [
            'name' => $r->name,
            'total' => (float) $r->total,
        ])->all();
    }

    /**
     * @return list<array{name: string, total: float}>|null
     */
    private static function categorySplit(int $companyId, ?PayrollPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->selectRaw('payroll_category as name, SUM(net_salary) as total')
            ->groupBy('payroll_category')
            ->orderByDesc('total')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        return $records->map(fn ($r) => [
            'name' => $r->name === PayrollCategory::Crew->value ? 'Crew' : ($r->name === PayrollCategory::Office->value ? 'Office' : $r->name),
            'total' => (float) $r->total,
        ])->all();
    }
}
