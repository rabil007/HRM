export type CrewRankPolicyItem = {
    rank_id: number;
    rank_name: string;
    is_active: boolean;
    global_tour_of_duty_days: number | null;
    company_tour_of_duty_days: number | null;
    policy_id: number | null;
    resolved_tour_of_duty_days: number | null;
    resolved_tour_of_duty_source: string | null;
};

export type CrewRankPolicyPagePermissions = {
    view: boolean;
    update: boolean;
};

export type CrewRankPolicyFormData = {
    rank_id: number | null;
    tour_of_duty_days: number | '';
};
