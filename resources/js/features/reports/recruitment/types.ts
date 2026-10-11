import type { PaginationMeta } from '@/types/pagination';

export type SelectOption = {
    value: string;
    label: string;
};

export type RecruitmentCandidateRow = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    nationality: string | null;
    requirement: {
        id: number;
        requirement_number: string;
        status: string | null;
        status_label: string | null;
    };
    client_name: string | null;
    project_title: string | null;
    position_title: string;
    recruiter_name: string | null;
    stage: string;
    stage_label: string;
    stage_badge: string;
    interview_outcome: string | null;
    interview_outcome_label: string | null;
    expected_joining_date: string | null;
    actual_joining_date: string | null;
    joining_readiness_status: string | null;
    joining_readiness_label: string | null;
    joining_readiness_badge: string | null;
    conversion_status: 'converted' | 'pending' | 'not_applicable';
    conversion_status_label: string;
    employee: {
        id: number | null;
        name: string | null;
        employee_no: string | null;
        can_view: boolean;
    } | null;
    time_to_hire_days: number | null;
    fulfillment_duration_days: number | null;
    created_at: string | null;
};

export type RecruitmentReportFilters = {
    requirement_id: string;
    client_id: string;
    project_id: string;
    position_id: string;
    recruiter_id: string;
    stage: string;
    joining_date_from: string;
    joining_date_to: string;
    conversion_status: string;
    search: string;
    sort: string;
    direction: string;
};

export type RecruitmentReportSummary = {
    applications_total: number;
    selected_count: number;
    joining_count: number;
    joined_count: number;
    rejected_count: number;
    converted_count: number;
    headcount_total: number;
    headcount_confirmed_joined: number;
    headcount_remaining: number;
    headcount_overfill: number;
};

export type RecruitmentReportProps = {
    candidates: RecruitmentCandidateRow[];
    pagination: PaginationMeta;
    summary: RecruitmentReportSummary;
    filters: RecruitmentReportFilters;
    filter_options: {
        requirements: SelectOption[];
        clients: SelectOption[];
        projects: SelectOption[];
        positions: SelectOption[];
        recruiters: SelectOption[];
        stages: SelectOption[];
        conversion_statuses: SelectOption[];
    };
    can: {
        view: boolean;
        export: boolean;
        view_candidates: boolean;
        view_employees: boolean;
    };
};
