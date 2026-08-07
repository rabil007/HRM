export type CrewOperationsPagePermissions = {
    overview: boolean;
    planning: boolean;
    vessel_manning: boolean;
    assignments: boolean;
    corrections_view: boolean;
    corrections_approve: boolean;
};

export type CrewOperationsDailyPulse = {
    onboard_now: number;
    joins_next_7_days: number;
    signoffs_next_7_days: number;
    signoffs_overdue: number;
    coverage_risks: {
        current: number;
        upcoming: number;
    };
};

export type CrewOperationsActionItem = {
    type: string;
    severity: 'critical' | 'warning' | 'info';
    title: string;
    subtitle: string | null;
    problem: string;
    meta: string | null;
    href: string;
};

export type CrewOperationsNextDay = {
    date: string;
    label: string;
    joins: number;
    signoffs: number;
};

export type CrewOperationsManningReliefRisk = {
    kind: 'actual' | 'projected' | 'relief';
    risk: string;
    vessel_id: number | null;
    vessel_name: string;
    rank_id: number | null;
    rank_name: string;
    when: string;
    href: string;
    employee_name?: string | null;
};

export type CrewOperationsProjectedManningCriticalPosition = {
    vessel_id: number;
    vessel_name: string;
    rank_id: number;
    rank_name: string;
    required_count: number;
    minimum_projected_count: number;
    maximum_gap: number;
    next_gap_date: string | null;
    status: 'current_gap' | 'future_gap' | string;
    status_label: string;
};

export type CrewOperationsProjectedManning = {
    horizon_days: number;
    from: string;
    to: string;
    current_gap_positions: number;
    future_gap_positions: number;
    covered_positions: number;
    overlap_positions: number;
    projected_shortfall_days: number;
    next_gap_date: string | null;
    critical_positions: CrewOperationsProjectedManningCriticalPosition[];
};

export type CrewOperationsDashboardProps = {
    today: string;
    company_timezone: string;
    daily_pulse: CrewOperationsDailyPulse;
    action_required: CrewOperationsActionItem[];
    next_seven_days: CrewOperationsNextDay[];
    manning_relief_risks: CrewOperationsManningReliefRisk[];
    projected_manning: CrewOperationsProjectedManning | null;
    max_home_days: number;
    can: CrewOperationsPagePermissions;
};
