import { Calculator, FileText, CheckCircle, Clock } from 'lucide-react';
import { index as payrollIndex } from '@/routes/payroll';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { PayrollDashboardSummary } from '../dashboard-types';

type PayrollSectionProps = {
    summary?: PayrollDashboardSummary;
};

export function PayrollSection({ summary }: PayrollSectionProps) {
    if (!summary) {
        return null;
    }

    const formattedLastPaidTotal = summary.last_paid_period_total !== null
        ? summary.last_paid_period_total.toLocaleString('en-US', { minimumFractionDigits: 2 })
        : null;

    return (
        <DashboardSection
            title="Payroll Summary"
            description="Active payroll cycles, processing periods, and payout history"
            icon={Calculator}
            actionLabel="Open Payroll Module"
            actionHref={payrollIndex.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Draft Periods"
                    value={summary.draft_periods}
                    subtitle="Unprocessed payroll drafts"
                    icon={FileText}
                    iconColor="text-blue-500"
                    href={payrollIndex.url({ query: { status: 'draft' } })}
                />

                <DashboardMetricCard
                    title="Processing / Awaiting Approval"
                    value={summary.processing_periods}
                    subtitle="Requires review or approval"
                    icon={Clock}
                    iconColor="text-amber-500"
                    badgeText={summary.processing_periods > 0 ? 'Pending' : 'Clear'}
                    badgeVariant={summary.processing_periods > 0 ? 'warning' : 'success'}
                    href={payrollIndex.url({ query: { status: 'processing' } })}
                />

                <DashboardMetricCard
                    title="Last Paid Period"
                    value={summary.last_paid_period_name || 'None'}
                    subtitle={formattedLastPaidTotal ? `Total Net: ${formattedLastPaidTotal}` : 'No completed payouts'}
                    icon={CheckCircle}
                    iconColor="text-emerald-500"
                    href={payrollIndex.url({ query: { status: 'paid' } })}
                />

                <DashboardMetricCard
                    title="Active Periods"
                    value={summary.draft_periods + summary.processing_periods}
                    subtitle="Total in-flight payroll cycles"
                    icon={Calculator}
                    iconColor="text-purple-500"
                    href={payrollIndex.url()}
                />
            </div>
        </DashboardSection>
    );
}
