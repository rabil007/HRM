import { Users, UserCheck, UserPlus, Building2 } from 'lucide-react';
import { departments, employees } from '@/routes/organization';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type {
    EmployeeAnalytics,
    OrganizationSnapshot,
} from '../dashboard-types';

type WorkforceSectionProps = {
    analytics?: EmployeeAnalytics;
    snapshot?: OrganizationSnapshot;
};

export function WorkforceSection({
    analytics,
    snapshot,
}: WorkforceSectionProps) {
    if (!analytics) {
        return null;
    }

    return (
        <DashboardSection
            title="Workforce & Organization Overview"
            description="Active workforce structure and headcount metrics"
            icon={Users}
            actionLabel="Manage Employees"
            actionHref={employees.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Total Employees"
                    value={analytics.total}
                    subtitle={`${analytics.active} active • ${analytics.inactive} inactive`}
                    icon={Users}
                    iconColor="text-blue-500"
                    href={employees.url()}
                />

                <DashboardMetricCard
                    title="Active Workforce"
                    value={analytics.active}
                    subtitle={`${analytics.on_leave} currently on leave`}
                    icon={UserCheck}
                    iconColor="text-emerald-500"
                    badgeText={`${Math.round((analytics.active / (analytics.total || 1)) * 100)}%`}
                    badgeVariant="success"
                    href={employees.url({ query: { status: 'active' } })}
                />

                <DashboardMetricCard
                    title="New Hires This Month"
                    value={analytics.new_hires_this_month}
                    subtitle="Based on official hire date"
                    icon={UserPlus}
                    iconColor="text-indigo-500"
                    badgeText="Hire Date"
                    badgeVariant="info"
                    href={employees.url()}
                />

                <DashboardMetricCard
                    title="Departments & Branches"
                    value={`${snapshot?.departments || 0} / ${snapshot?.branches || 0}`}
                    subtitle="Departments / Active Branches"
                    icon={Building2}
                    iconColor="text-purple-500"
                    href={departments.url()}
                />
            </div>
        </DashboardSection>
    );
}
