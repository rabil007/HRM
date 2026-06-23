import type { RecentActivityItem } from '@/components/recent-activity-card';
import type { DeploymentSummary } from '@/features/organization/crew-deployments/types';

export type CrewOperationsAlertCounts = {
    needs_update: number;
    due_soon: number;
    overdue_home: number;
    upcoming_planning: number;
    manning_gaps: number;
};

export type CrewOperationsAttentionItem = {
    type: 'needs_update' | 'overdue_home' | 'due_soon' | 'upcoming_join' | 'manning_gap';
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

export type CrewOperationsManningGapItem = {
    vessel_id: number;
    vessel_name: string;
    rank_id: number;
    rank_name: string;
    required_count: number;
    actual_count: number;
    gap: number;
};

export type CrewOperationsManningGaps = {
    understaffed_positions: number;
    total_shortfall: number;
    items: CrewOperationsManningGapItem[];
};

export type CrewOperationsDeploymentTrendPoint = {
    month: string;
    joins: number;
    disembarks: number;
};

export type CrewOperationsPagePermissions = {
    overview: boolean;
    planning: boolean;
    vessel_manning: boolean;
    deployments: boolean;
    deployments_create: boolean;
};

export type CrewOperationsDashboardProps = {
    deployment_summary: DeploymentSummary;
    alert_counts: CrewOperationsAlertCounts;
    attention_items: CrewOperationsAttentionItem[];
    manning_gaps: CrewOperationsManningGaps;
    deployment_trends: CrewOperationsDeploymentTrendPoint[];
    upcoming_planning: CrewOperationsUpcomingPlanningItem[];
    pool_snapshot: { count: number };
    recent_activity: RecentActivityItem[];
    max_home_days: number;
    can: CrewOperationsPagePermissions;
    can_view_audit: boolean;
};
