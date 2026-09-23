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
    client_name: string;
    status: string;
    required_by_date: string;
    matching_positions: string[];
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
