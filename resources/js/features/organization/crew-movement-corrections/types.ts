import type { PaginationMeta } from '@/types/pagination';

export type CrewMovementCorrectionStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type CrewMovementCorrectionAgeStatus =
    | 'on_time'
    | 'needs_attention'
    | 'overdue'
    | 'not_applicable';

export type CrewMovementCorrectionFieldValue = {
    value: unknown;
    display: string | null;
};

export type CrewMovementCorrectionValues = Record<
    string,
    CrewMovementCorrectionFieldValue
>;

export type CrewMovementCorrectionListItem = {
    id: number;
    status: CrewMovementCorrectionStatus;
    status_label: string;
    reason: string | null;
    decision_notes: string | null;
    requested_at: string | null;
    decided_at: string | null;
    pending_days: number | null;
    pending_age_label: string | null;
    age_status: CrewMovementCorrectionAgeStatus;
    age_status_label: string | null;
    needs_attention: boolean;
    is_overdue: boolean;
    overdue_days: number;
    assignment: {
        id: number;
        assignment_no: string;
        employee: {
            id: number;
            name: string;
            employee_no: string | null;
        } | null;
        vessel: { id: number; name: string } | null;
    } | null;
    phase: {
        id: number;
        phase_code: string;
        phase_label: string;
        status: string;
        status_label: string;
    } | null;
    requester: { id: number; name: string } | null;
    decision_maker: { id: number; name: string } | null;
    field_count: number;
    has_conflict: boolean;
};

export type CrewMovementCorrectionDetail = CrewMovementCorrectionListItem & {
    original_values: CrewMovementCorrectionValues;
    proposed_values: CrewMovementCorrectionValues;
    applied_values: CrewMovementCorrectionValues | null;
    live_values: CrewMovementCorrectionValues;
    can_approve: boolean;
    can_reject: boolean;
    can_cancel: boolean;
};

export type CrewMovementCorrectionPagePermissions = {
    view: boolean;
    request: boolean;
    approve: boolean;
    override: boolean;
};

export type CrewMovementCorrectionStatusCounts = {
    all: number;
    pending: number;
    approved: number;
    rejected: number;
    cancelled: number;
    my_requests: number;
};

export type CrewMovementCorrectionFilters = {
    status: string;
    scope: string;
    age_status: string;
};

export type CrewMovementCorrectionSummaryCounts = {
    pending: number;
    needs_attention: number;
    overdue: number;
    my_requests: number;
};

export type CrewMovementCorrectionsIndexProps = {
    corrections: CrewMovementCorrectionListItem[];
    pagination: PaginationMeta;
    status_counts: CrewMovementCorrectionStatusCounts;
    summary_counts: CrewMovementCorrectionSummaryCounts;
    search: string;
    filters: CrewMovementCorrectionFilters;
    can: CrewMovementCorrectionPagePermissions;
};

export type CrewMovementCorrectionShowProps = {
    correction: CrewMovementCorrectionDetail;
    can: CrewMovementCorrectionPagePermissions;
};

export const CORRECTION_FIELD_LABELS: Record<string, string> = {
    actual_start_at: 'Actual Start',
    actual_end_at: 'Actual End',
    remarks: 'Remarks',
    'details.provider': 'Training Provider',
    'details.course': 'Training Course',
    vessel_id: 'Vessel',
    rank_id: 'Rank',
    client_id: 'Client',
    company_visa_type_id: 'Visa Type',
};

export function correctionFieldLabel(field: string): string {
    if (CORRECTION_FIELD_LABELS[field]) {
        return CORRECTION_FIELD_LABELS[field];
    }

    return field
        .replace('details.', '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function correctionFieldDisplay(
    values: CrewMovementCorrectionValues,
    field: string,
): string {
    const entry = values[field];

    if (!entry || entry.display === null || entry.display === '') {
        return '—';
    }

    return entry.display;
}
