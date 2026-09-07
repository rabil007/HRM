export type VesselTypeOption = {
    id: number;
    name: string;
};

export type VesselManningLine = {
    id: number;
    rank_id: number;
    rank_name: string;
    required_count: number;
};

export type VesselRow = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type: { id: number; name: string } | null;
    vessel_type_name: string | null;
    grt: string | number | null;
    bhp: number | null;
    official_no: string | null;
    call_sign: string | null;
    imo_no: string | null;
    certificate_original_filename: string | null;
    certificate_url: string | null;
    is_active: boolean;
    manning: VesselManningLine[];
    total_required: number;
    ranks_configured: number;
    manning_health?: VesselManningHealthCompact | null;
};

export type VesselDetails = VesselRow & {
    created_at: string | null;
    updated_at: string | null;
};

export type VesselSummary = {
    manning_ranks: number;
    total_required: number;
    sea_services: number;
    active_crew: number;
};

export type VesselPageCan = {
    create: boolean;
    update: boolean;
    delete: boolean;
    view_manning: boolean;
    view_assignments: boolean;
    view_planning: boolean;
};

export type VesselManningHealthStatus =
    | 'healthy'
    | 'at_risk'
    | 'critical'
    | 'not_configured';

export type VesselManningHealthSignoff = {
    assignment_id: number;
    employee_id: number | null;
    employee_name: string | null;
    planned_signoff_at: string;
};

export type VesselManningHealthRelief = {
    source_assignment_id: number;
    source_employee_name: string | null;
    source_planned_signoff_at: string | null;
    relief_status: string;
    relief_status_label: string;
    relief_employee_name: string | null;
    relief_phase_label: string | null;
    relief_planned_join_date: string | null;
    mobilisation_readiness_label: string | null;
};

export type VesselManningHealthRank = {
    rank_id: number;
    rank_name: string;
    required: number;
    onboard: number;
    projected: number;
    current_gap: number;
    future_gap: number;
    next_gap_date: string | null;
    status: VesselManningHealthStatus;
    status_label: string;
    reason: string;
    overlap_excess: number;
    relief_summary: string;
    relief_status: string | null;
    relief_status_label: string | null;
    ready_relief_count: number;
    signoffs: VesselManningHealthSignoff[];
    reliefs: VesselManningHealthRelief[];
    mobilisation_readiness_label: string | null;
};

export type VesselManningHealth = {
    status: VesselManningHealthStatus;
    status_label: string;
    horizon_days: number;
    from: string;
    to: string;
    reason: string;
    required: number;
    onboard: number;
    current_gap: number;
    future_gap: number;
    next_gap_date: string | null;
    overlap_excess: number;
    signing_off_within_14_days: number;
    ready_reliefs: number;
    projected_shortfall_days: number;
    include_crew_details: boolean;
    ranks: VesselManningHealthRank[];
};

export type VesselManningHealthCompact = {
    status: VesselManningHealthStatus;
    status_label: string;
    required: number;
    onboard: number;
    current_gap: number;
    future_gap: number;
    next_gap_date: string | null;
    reason: string;
};

export type VesselFormData = {
    name: string;
    vessel_type_id: number | '';
    grt: string;
    bhp: string;
    official_no: string;
    call_sign: string;
    imo_no: string;
    certificate: File | null;
    is_active: boolean;
};
