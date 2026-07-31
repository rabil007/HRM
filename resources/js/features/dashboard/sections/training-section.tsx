import { Award, AlertTriangle, Clock, ShieldCheck } from 'lucide-react';
import { training } from '@/routes/organization';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { TrainingDashboardSummary } from '../dashboard-types';

type TrainingSectionProps = {
    summary?: TrainingDashboardSummary;
};

export function TrainingSection({ summary }: TrainingSectionProps) {
    if (!summary) {
        return null;
    }

    return (
        <DashboardSection
            title="Training & Certifications"
            description="Employee training records, certification validity, and expiration tracking"
            icon={Award}
            actionLabel="Training Registry"
            actionHref={training.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Total Certificates"
                    value={summary.total}
                    subtitle="Assigned training certificates"
                    icon={Award}
                    iconColor="text-blue-500"
                    href={training.url()}
                />

                <DashboardMetricCard
                    title="Expired Certificates"
                    value={summary.expired}
                    subtitle="Passed certification expiry"
                    icon={AlertTriangle}
                    iconColor="text-rose-500"
                    badgeText={summary.expired > 0 ? 'Critical' : 'None'}
                    badgeVariant={summary.expired > 0 ? 'danger' : 'success'}
                    href={training.url({ query: { status: 'expired' } })}
                />

                <DashboardMetricCard
                    title="Expiring Within 7 Days"
                    value={summary.expiring_7}
                    subtitle={`${summary.expiring_30} expiring in 30 days`}
                    icon={Clock}
                    iconColor="text-amber-500"
                    badgeVariant={summary.expiring_7 > 0 ? 'warning' : 'default'}
                    href={training.url({ query: { status: 'expiring_7' } })}
                />

                <DashboardMetricCard
                    title="Active / Valid"
                    value={Math.max(0, summary.total - summary.expired)}
                    subtitle="Valid certificates in effect"
                    icon={ShieldCheck}
                    iconColor="text-emerald-500"
                    href={training.url({ query: { status: 'valid' } })}
                />
            </div>
        </DashboardSection>
    );
}
