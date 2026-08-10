import {
    FileSignature,
    CheckCircle2,
    Clock,
    AlertTriangle,
    UserX,
} from 'lucide-react';
import { contracts } from '@/routes/organization';
import { noContract } from '@/routes/organization/contracts';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { ContractsDashboardSummary } from '../dashboard-types';

type ContractsSectionProps = {
    summary?: ContractsDashboardSummary;
};

export function ContractsSection({ summary }: ContractsSectionProps) {
    if (!summary) {
        return null;
    }

    return (
        <DashboardSection
            title="Employment Contracts"
            description="Active contracts, upcoming expirations, and missing contract alerts"
            icon={FileSignature}
            actionLabel="Contract Directory"
            actionHref={contracts.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Active Contracts"
                    value={summary.active}
                    subtitle={`${summary.total_contracts} total in system`}
                    icon={CheckCircle2}
                    iconColor="text-emerald-500"
                    href={contracts.url({ query: { status: 'active' } })}
                />

                <DashboardMetricCard
                    title="Ending Within 30 Days"
                    value={summary.ending_30}
                    subtitle={`${summary.ending_60} ending in 60 days`}
                    icon={Clock}
                    iconColor="text-amber-500"
                    badgeText={summary.ending_30 > 0 ? 'Expiring' : undefined}
                    badgeVariant={summary.ending_30 > 0 ? 'warning' : 'default'}
                    href={contracts.url({ query: { lifecycle: 'ending_30' } })}
                />

                <DashboardMetricCard
                    title="No Contract Employees"
                    value={summary.no_contract_employees}
                    subtitle="Active employees missing contract"
                    icon={AlertTriangle}
                    iconColor="text-rose-500"
                    badgeText={
                        summary.no_contract_employees > 0
                            ? 'Missing'
                            : 'Complete'
                    }
                    badgeVariant={
                        summary.no_contract_employees > 0 ? 'danger' : 'success'
                    }
                    href={noContract.url()}
                />

                <DashboardMetricCard
                    title="Ended Contracts"
                    value={summary.ended}
                    subtitle="Expired or terminated contracts"
                    icon={UserX}
                    iconColor="text-muted-foreground"
                    href={contracts.url({ query: { status: 'ended' } })}
                />
            </div>
        </DashboardSection>
    );
}
