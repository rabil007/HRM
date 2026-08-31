export type DocumentRequirementPayload = {
    is_required: boolean;
    required_for_all: boolean;
    department_ids: number[];
    position_ids: number[];
    rank_ids: number[];
    project_ids: number[];
    require_issue_date: boolean;
    require_expiry_date: boolean;
    require_document_number: boolean;
    label: string;
};

export type DocumentTypeRequirementTarget = {
    departments: Array<{ id: number; name: string }>;
    positions: Array<{ id: number; title: string }>;
    ranks: Array<{ id: number; name: string }>;
    projects: Array<{ id: number; title: string }>;
};

export type DocumentTypeTrackedDetail = {
    key: string;
    label: string;
};

export type DocumentTypeDetailRequirement = DocumentRequirementPayload & {
    requirement_label: 'Required' | 'Optional';
    scope_kind: 'optional' | 'all_employees' | 'selected_groups';
    scope_summary: string;
    applies_to_label: string;
    who_needs_copy: string;
    matching_rule_applies: boolean;
    targets: DocumentTypeRequirementTarget;
    tracked_details: DocumentTypeTrackedDetail[];
};

export type DocumentTypeComplianceLink = {
    label: string;
    href: string;
};

export type DocumentTypeDetail = {
    id: number;
    title: string;
    is_active: boolean;
    status_label: string;
    requirement: DocumentTypeDetailRequirement;
    compliance_links: DocumentTypeComplianceLink[];
};

export type DocumentTypeRow = {
    id: number;
    title: string;
    is_active: boolean;
    requirement: DocumentRequirementPayload;
};

export type DepartmentOption = {
    id: number;
    name: string;
};

export type PositionOption = {
    id: number;
    title: string;
};

export type RankOption = {
    id: number;
    name: string;
};

export type ProjectOption = {
    id: number;
    title: string;
};

export type DocumentTypeFormData = {
    title: string;
    is_active: boolean;
    is_required: boolean;
    required_for_all: boolean;
    department_ids: number[];
    position_ids: number[];
    rank_ids: number[];
    project_ids: number[];
    require_issue_date: boolean;
    require_expiry_date: boolean;
    require_document_number: boolean;
    redirect_to?: 'show' | '';
};

export const emptyRequirement: DocumentRequirementPayload = {
    is_required: false,
    required_for_all: false,
    department_ids: [],
    position_ids: [],
    rank_ids: [],
    project_ids: [],
    require_issue_date: false,
    require_expiry_date: false,
    require_document_number: false,
    label: 'Optional',
};

export const initialDocumentTypeForm: DocumentTypeFormData = {
    title: '',
    is_active: true,
    is_required: false,
    required_for_all: false,
    department_ids: [],
    position_ids: [],
    rank_ids: [],
    project_ids: [],
    require_issue_date: false,
    require_expiry_date: false,
    require_document_number: false,
};

export function documentTypeExpiryLabel(
    requirement: DocumentRequirementPayload | undefined,
): string {
    if (!requirement?.is_required || !requirement.require_expiry_date) {
        return '—';
    }

    return 'Tracked';
}

export function documentTypeRequirementStatus(
    requirement: DocumentRequirementPayload | undefined,
): 'Required' | 'Optional' {
    return requirement?.is_required ? 'Required' : 'Optional';
}

export function documentTypeAppliesToLabel(
    requirement: DocumentRequirementPayload | undefined,
): string {
    if (!requirement?.is_required) {
        return '—';
    }

    if (requirement.required_for_all) {
        return 'All employees';
    }

    return requirement.label && requirement.label !== 'Optional'
        ? requirement.label
        : 'Selected groups';
}

export function documentTypeToRow(
    documentType: Pick<
        DocumentTypeDetail,
        'id' | 'title' | 'is_active' | 'requirement'
    >,
): DocumentTypeRow {
    const requirement = documentType.requirement;

    return {
        id: documentType.id,
        title: documentType.title,
        is_active: documentType.is_active,
        requirement: {
            is_required: requirement.is_required,
            required_for_all: requirement.required_for_all,
            department_ids: requirement.department_ids,
            position_ids: requirement.position_ids,
            rank_ids: requirement.rank_ids,
            project_ids: requirement.project_ids,
            require_issue_date: requirement.require_issue_date,
            require_expiry_date: requirement.require_expiry_date,
            require_document_number: requirement.require_document_number,
            label: requirement.label,
        },
    };
}

export function requirementToFormData(
    documentType: DocumentTypeRow,
    options?: { redirectToShow?: boolean },
): DocumentTypeFormData {
    const requirement = documentType.requirement ?? emptyRequirement;

    return {
        title: documentType.title,
        is_active: documentType.is_active,
        is_required: requirement.is_required,
        required_for_all: requirement.required_for_all,
        department_ids: requirement.department_ids,
        position_ids: requirement.position_ids,
        rank_ids: requirement.rank_ids,
        project_ids: requirement.project_ids,
        require_issue_date: requirement.require_issue_date,
        require_expiry_date: requirement.require_expiry_date,
        require_document_number: requirement.require_document_number,
        ...(options?.redirectToShow ? { redirect_to: 'show' as const } : {}),
    };
}
