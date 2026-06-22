import type { DeploymentSummary } from '@/features/organization/crew-deployments/types';
import type { RecentActivityItem } from '@/components/recent-activity-card';

export type CrewOperationsAlertCounts = {
    needs_update: number;
    due_soon: number;
    overdue_home: number;
    upcoming_planning: number;
};

export type CrewOperationsAttentionItem = {
    type: 'needs_update' | 'overdue_home' | 'due_soon' | 'upcoming_join';
    title: string;
    subtitle: string | null;
    hint: string;
    href: string;
    severity: 'critical' | 'warning' | 'info';
};

export type CrewOperationsUpcomingPlanningItem = {
    id: number;
    employee_name: string | null;
    vessel_name: string | null;
    rank_name: string | null;
    planned_join_date: string;
    planned_leave_date: string;
};

export type CrewOperationsPagePermissions = {
    planning: boolean;
    vessel_manning: boolean;
    deployments: boolean;
    deployments_create: boolean;
};

export type CrewOperationsDashboardProps = {
    deployment_summary: DeploymentSummary;
    alert_counts: CrewOperationsAlertCounts;
    attention_items: CrewOperationsAttentionItem[];
    upcoming_planning: CrewOperationsUpcomingPlanningItem[];
    pool_snapshot: { count: number };
    recent_activity: RecentActivityItem[];
    max_home_days: number;
    can: CrewOperationsPagePermissions;
    can_view_audit: boolean;
};
