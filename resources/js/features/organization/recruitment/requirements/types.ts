import type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementDeadlineHealth,
    RequirementFilters,
    RequirementIndexProps,
    RequirementIndexRow,
    RequirementLine,
    RequirementPagePermissions,
    RequirementPriority,
    RequirementShowProps,
    RequirementStatus,
    RequirementSummaryCardsData,
    RequirementTab,
    UserOption,
} from '@/types/recruitment';

export type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementDeadlineHealth,
    RequirementFilters,
    RequirementIndexProps,
    RequirementIndexRow,
    RequirementLine,
    RequirementPagePermissions,
    RequirementPriority,
    RequirementShowProps,
    RequirementStatus,
    RequirementSummaryCardsData,
    RequirementTab,
    UserOption,
};

export type SimilarRequirementMatch = {
    id: number;
    requirement_number: string;
    client_id?: number;
    client_name: string;
    project_id?: number | null;
    project_title?: string | null;
    status: string;
    status_label?: string;
    required_by_date: string;
    required_by_date_formatted?: string;
    total_headcount?: number;
    matching_positions: string[];
    positions?: Array<{
        position_id: number;
        position_title: string;
        required_headcount: number;
        status: string;
        status_label: string;
    }>;
};

export type FormPositionLineInput = {
    id?: number;
    position_id: number | string;
    required_headcount: number;
    line_notes?: string;
};

export type RequirementFormState = {
    client_id: number | string;
    project_id: number | string | '';
    client_reference_number: string;
    location: string;
    assigned_to: number | string | '';
    request_received_date: string;
    required_by_date: string;
    priority: RequirementPriority;
    notes: string;
    positions: FormPositionLineInput[];
    attachment?: File | null;
    force_create?: boolean;
};
