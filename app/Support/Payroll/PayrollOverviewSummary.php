<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PayrollOverviewSummary
{
    /**
     * @return array{
     *     draft_periods: int,
     *     processing_periods: int,
     *     approved_periods: int,
     *     paid_periods: int,
     *     total_employees_in_payroll: int,
     *     crew_employee_count: int,
     *     office_employee_count: int,
     *     last_paid_period_total: float|null,
     *     last_paid_period_name: string|null,
     *     last_paid_period_gross: float|null,
     *     last_paid_period_deductions: float|null,
     *     last_paid_period_avg_net: float|null,
     *     last_paid_period_employee_count: int|null,
     *     ytd_payroll_total: float,
     *     ytd_gross_total: float,
     *     ytd_deductions_total: float,
     *     ytd_overtime_total: float,
     *     total_paid_periods_all_time: int,
     *     payroll_efficiency_pct: float|null,
     *     mom_net_change_pct: float|null,
     *     mom_net_change_amount: float|null,
     *     avg_monthly_payroll_6m: float,
     *     monthly_trend: list<array{month: string, total: float, count: int, avg: float, gross: float, deductions: float, overtime: float}>,
     *     monthly_category_costs: list<array{month: string, crew: float, office: float}>,
     *     attention_items: list<array{title: string, subtitle: string, type: string, severity: string}>,
     *     salary_breakdown: array{basic: float, allowances: float, deductions: float}|null,
     *     department_costs: list<array{name: string, total: float}>|null,
     *     department_employee_counts: list<array{name: string, count: int}>,
     *     category_split: list<array{name: string, total: float}>|null,
     *     wps_status_breakdown: array{pending: int, submitted: int}|null,
     *     top_earners: list<array{name: string, employee_no: string, department: string|null, net_salary: float, category: string}>|null,
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

        // Last paid period
        $lastPaidPeriod = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', PayrollPeriodStatus::Paid)
            ->orderByDesc('payment_date')
            ->orderByDesc('end_date')
            ->first();

        $lastPaidTotal = null;
        $lastPaidName = null;
        $lastPaidGross = null;
        $lastPaidDeductions = null;
        $lastPaidAvgNet = null;
        $lastPaidEmployeeCount = null;

        if ($lastPaidPeriod !== null) {
            $sums = PayrollRecord::query()
                ->where('company_id', $companyId)
                ->where('period_id', $lastPaidPeriod->id)
                ->selectRaw('SUM(net_salary) as net, SUM(gross_salary) as gross, SUM(total_deductions) as deductions, COUNT(*) as employee_count')
                ->first();

            if ($sums !== null) {
                $lastPaidTotal = (float) $sums->net;
                $lastPaidGross = (float) $sums->gross;
                $lastPaidDeductions = (float) $sums->deductions;
                $lastPaidEmployeeCount = (int) $sums->employee_count;
                $lastPaidAvgNet = $lastPaidEmployeeCount > 0
                    ? round($lastPaidTotal / $lastPaidEmployeeCount, 2)
                    : null;
            }

            $lastPaidName = $lastPaidPeriod->name;
        }

        // Active payroll employees (crew + office)
        $crewCount = PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Crew);
        $officeCount = PayrollEmployeeQuery::activeCount($companyId, PayrollCategory::Office);
        $totalEmployees = $crewCount + $officeCount;

        // YTD aggregates (paid records from start of current year)
        $ytdSums = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->join('payroll_periods', 'payroll_records.period_id', '=', 'payroll_periods.id')
            ->where('payroll_periods.status', PayrollPeriodStatus::Paid->value)
            ->where('payroll_periods.payment_date', '>=', Carbon::now()->startOfYear())
            ->selectRaw('SUM(payroll_records.net_salary) as net, SUM(payroll_records.gross_salary) as gross, SUM(payroll_records.total_deductions) as deductions, SUM(payroll_records.overtime_pay) as overtime')
            ->first();

        $ytdTotal = (float) ($ytdSums?->net ?? 0);
        $ytdGross = (float) ($ytdSums?->gross ?? 0);
        $ytdDeductions = (float) ($ytdSums?->deductions ?? 0);
        $ytdOvertime = (float) ($ytdSums?->overtime ?? 0);

        // Payroll efficiency: net/gross ratio %
        $payrollEfficiencyPct = $ytdGross > 0
            ? round(($ytdTotal / $ytdGross) * 100, 1)
            : null;

        // Monthly payroll trend (last 6 months) — extended with gross, deductions, overtime
        $monthlyTrend = self::monthlyTrend($companyId);

        // Month-over-month change (compare last two months with data)
        $momNetChangePct = null;
        $momNetChangeAmount = null;
        $monthsWithData = array_values(array_filter($monthlyTrend, fn ($m) => $m['total'] > 0));

        if (count($monthsWithData) >= 2) {
            $last = $monthsWithData[count($monthsWithData) - 1];
            $prev = $monthsWithData[count($monthsWithData) - 2];

            if ($prev['total'] > 0) {
                $momNetChangeAmount = $last['total'] - $prev['total'];
                $momNetChangePct = round((($last['total'] - $prev['total']) / $prev['total']) * 100, 1);
            }
        }

        // Average monthly payroll over last 6 months (only paid months)
        $avgMonthly6m = count($monthsWithData) > 0
            ? round(array_sum(array_column($monthsWithData, 'total')) / count($monthsWithData), 2)
            : 0.0;

        // Monthly category costs (crew vs office over 6 months)
        $monthlyCategoryCosts = self::monthlyCategoryCosts($companyId);

        // Attention items
        $attentionItems = self::attentionItems($periods, $companyId);

        // Analytics
        $salaryBreakdown = self::salaryBreakdown($companyId, $lastPaidPeriod);
        $departmentCosts = self::departmentCosts($companyId, $lastPaidPeriod);
        $categorySplit = self::categorySplit($companyId, $lastPaidPeriod);
        $wpsStatusBreakdown = self::wpsStatusBreakdown($companyId, $lastPaidPeriod);
        $topEarners = self::topEarners($companyId, $lastPaidPeriod);
        $departmentEmployeeCounts = self::departmentEmployeeCounts($companyId);

        return [
            'draft_periods' => $draftPeriods,
            'processing_periods' => $processingPeriods,
            'approved_periods' => $approvedPeriods,
            'paid_periods' => $paidPeriods,
            'total_employees_in_payroll' => $totalEmployees,
            'crew_employee_count' => $crewCount,
            'office_employee_count' => $officeCount,
            'last_paid_period_total' => $lastPaidTotal,
            'last_paid_period_name' => $lastPaidName,
            'last_paid_period_gross' => $lastPaidGross,
            'last_paid_period_deductions' => $lastPaidDeductions,
            'last_paid_period_avg_net' => $lastPaidAvgNet,
            'last_paid_period_employee_count' => $lastPaidEmployeeCount,
            'ytd_payroll_total' => $ytdTotal,
            'ytd_gross_total' => $ytdGross,
            'ytd_deductions_total' => $ytdDeductions,
            'ytd_overtime_total' => $ytdOvertime,
            'total_paid_periods_all_time' => $paidPeriods,
            'payroll_efficiency_pct' => $payrollEfficiencyPct,
            'mom_net_change_pct' => $momNetChangePct,
            'mom_net_change_amount' => $momNetChangeAmount,
            'avg_monthly_payroll_6m' => $avgMonthly6m,
            'monthly_trend' => $monthlyTrend,
            'monthly_category_costs' => $monthlyCategoryCosts,
            'attention_items' => $attentionItems,
            'salary_breakdown' => $salaryBreakdown,
            'department_costs' => $departmentCosts,
            'department_employee_counts' => $departmentEmployeeCounts,
            'category_split' => $categorySplit,
            'wps_status_breakdown' => $wpsStatusBreakdown,
            'top_earners' => $topEarners,
        ];
    }

    /**
     * @return list<array{month: string, total: float, count: int, avg: float, gross: float, deductions: float, overtime: float}>
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
                'avg' => 0.0,
                'gross' => 0.0,
                'deductions' => 0.0,
                'overtime' => 0.0,
            ];
        }

        $records = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->join('payroll_periods', 'payroll_records.period_id', '=', 'payroll_periods.id')
            ->where('payroll_periods.payment_date', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->selectRaw('
                YEAR(payroll_periods.payment_date) as yr,
                MONTH(payroll_periods.payment_date) as mo,
                SUM(payroll_records.net_salary) as total,
                SUM(payroll_records.gross_salary) as gross,
                SUM(payroll_records.total_deductions) as deductions,
                SUM(payroll_records.overtime_pay) as overtime,
                COUNT(payroll_records.id) as cnt
            ')
            ->groupByRaw('YEAR(payroll_periods.payment_date), MONTH(payroll_periods.payment_date)')
            ->get();

        foreach ($months as &$month) {
            $match = $records->first(fn ($r) => (int) $r->yr === $month['year'] && (int) $r->mo === $month['month_num']);

            if ($match !== null) {
                $month['total'] = (float) $match->total;
                $month['gross'] = (float) $match->gross;
                $month['deductions'] = (float) $match->deductions;
                $month['overtime'] = (float) $match->overtime;
                $month['count'] = (int) $match->cnt;
                $month['avg'] = $month['count'] > 0
                    ? round($month['total'] / $month['count'], 2)
                    : 0.0;
            }

            unset($month['year'], $month['month_num']);
        }

        return array_values($months);
    }

    /**
     * @return list<array{month: string, crew: float, office: float}>
     */
    private static function monthlyCategoryCosts(int $companyId): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $months[] = [
                'month' => $date->format('M Y'),
                'year' => $date->year,
                'month_num' => $date->month,
                'crew' => 0.0,
                'office' => 0.0,
            ];
        }

        $records = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->join('payroll_periods', 'payroll_records.period_id', '=', 'payroll_periods.id')
            ->where('payroll_periods.payment_date', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->selectRaw("
                YEAR(payroll_periods.payment_date) as yr,
                MONTH(payroll_periods.payment_date) as mo,
                SUM(CASE WHEN payroll_records.payroll_category = 'crew' THEN payroll_records.net_salary ELSE 0 END) as crew,
                SUM(CASE WHEN payroll_records.payroll_category = 'office' THEN payroll_records.net_salary ELSE 0 END) as office
            ")
            ->groupByRaw('YEAR(payroll_periods.payment_date), MONTH(payroll_periods.payment_date)')
            ->get();

        foreach ($months as &$month) {
            $match = $records->first(fn ($r) => (int) $r->yr === $month['year'] && (int) $r->mo === $month['month_num']);

            if ($match !== null) {
                $month['crew'] = (float) $match->crew;
                $month['office'] = (float) $match->office;
            }

            unset($month['year'], $month['month_num']);
        }

        return array_values($months);
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private static function departmentEmployeeCounts(int $companyId): array
    {
        $rows = DB::table('employees')
            ->where('employees.company_id', $companyId)
            ->where('employees.status', 'active')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('employee_contracts')
                    ->whereColumn('employee_contracts.employee_id', 'employees.id')
                    ->where('employee_contracts.status', 'active');
            })
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('COALESCE(departments.name, "Unassigned") as name, COUNT(employees.id) as count')
            ->groupByRaw('COALESCE(departments.name, "Unassigned")')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'count' => (int) $r->count,
        ])->all();
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

    /**
     * @return array{pending: int, submitted: int}|null
     */
    private static function wpsStatusBreakdown(int $companyId, ?PayrollPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $counts = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->selectRaw("SUM(CASE WHEN wps_status = 'submitted' THEN 1 ELSE 0 END) as submitted, SUM(CASE WHEN wps_status IS NULL OR wps_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->first();

        if ($counts === null) {
            return null;
        }

        return [
            'pending' => (int) $counts->pending,
            'submitted' => (int) $counts->submitted,
        ];
    }

    /**
     * @return list<array{name: string, employee_no: string, department: string|null, net_salary: float, category: string}>|null
     */
    private static function topEarners(int $companyId, ?PayrollPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $records = PayrollRecord::query()
            ->where('payroll_records.company_id', $companyId)
            ->where('payroll_records.period_id', $period->id)
            ->join('employees', 'payroll_records.employee_id', '=', 'employees.id')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->selectRaw('employees.name, employees.employee_no, departments.name as department_name, payroll_records.net_salary, payroll_records.payroll_category')
            ->orderByDesc('payroll_records.net_salary')
            ->limit(5)
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        return $records->map(fn ($r) => [
            'name' => $r->name,
            'employee_no' => $r->employee_no,
            'department' => $r->department_name,
            'net_salary' => (float) $r->net_salary,
            'category' => $r->payroll_category === PayrollCategory::Crew->value ? 'Crew' : 'Office',
        ])->all();
    }
}
