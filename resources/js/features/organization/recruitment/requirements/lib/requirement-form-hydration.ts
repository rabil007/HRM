import type { RequirementDetail } from '@/types/recruitment';
import type { FormPositionLineInput } from '../types';

export function nullableIdToFormValue(
    value: number | null | undefined,
): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

export function nullableDateToFormValue(
    value: string | null | undefined,
): string {
    return value ?? '';
}

export function isRequirementPreparationStatus(
    status: RequirementDetail['status'] | undefined,
): boolean {
    return status === 'draft' || status === 'returned';
}

export function canRemoveRequirementPositionLine(input: {
    canEditPositionStructure: boolean;
    isPreparationEditable: boolean;
    positionCount: number;
}): boolean {
    if (!input.canEditPositionStructure || input.positionCount < 1) {
        return false;
    }

    if (input.positionCount === 1 && !input.isPreparationEditable) {
        return false;
    }

    return true;
}

export function shouldShowRequirementPositionStructureLockWarning(
    canEditPositionStructure: boolean,
): boolean {
    return !canEditPositionStructure;
}

export function mapRequirementLinesToFormPositions(
    lines: RequirementDetail['lines'] | undefined,
    currencyCode: string,
): FormPositionLineInput[] {
    if (!lines || lines.length === 0) {
        return [];
    }

    return lines.map((line) => ({
        id: line.id,
        position_id: String(line.position_id),
        required_headcount: line.required_headcount,
        salary_min:
            line.salary_min !== null && line.salary_min !== undefined
                ? String(line.salary_min)
                : '',
        salary_max:
            line.salary_max !== null && line.salary_max !== undefined
                ? String(line.salary_max)
                : '',
        salary_currency_code: line.salary_currency_code || currencyCode,
        line_notes: line.line_notes || '',
    }));
}

export function hydrateRequirementFormFromDetail(input: {
    requirement: RequirementDetail;
    today: string;
    currencyCode: string;
    defaultPositionLine: FormPositionLineInput;
}): {
    client_id: string;
    project_id: string;
    location: string;
    assigned_to: string;
    notification_recipient_ids: number[];
    request_received_date: string;
    required_by_date: string;
    priority: 'normal' | 'urgent';
    notes: string;
    positions: FormPositionLineInput[];
} {
    const { requirement, currencyCode, defaultPositionLine } = input;
    const preparation = isRequirementPreparationStatus(requirement.status);
    const mappedPositions = mapRequirementLinesToFormPositions(
        requirement.lines,
        currencyCode,
    );

    return {
        client_id: nullableIdToFormValue(requirement.client_id),
        project_id: nullableIdToFormValue(requirement.project_id),
        location: requirement.location ?? '',
        assigned_to: nullableIdToFormValue(requirement.assigned_to),
        notification_recipient_ids:
            requirement.notification_recipients?.map(
                (recipient) => recipient.id,
            ) ?? [],
        request_received_date: nullableDateToFormValue(
            requirement.request_received_date,
        ),
        required_by_date: nullableDateToFormValue(requirement.required_by_date),
        priority: requirement.priority || 'normal',
        notes: requirement.notes ?? '',
        positions:
            mappedPositions.length > 0
                ? mappedPositions
                : preparation
                  ? []
                  : [{ ...defaultPositionLine }],
    };
}

export function hydrateNewRequirementForm(input: {
    today: string;
    defaultPositionLine: FormPositionLineInput;
}): {
    client_id: string;
    project_id: string;
    location: string;
    assigned_to: string;
    notification_recipient_ids: number[];
    request_received_date: string;
    required_by_date: string;
    priority: 'normal' | 'urgent';
    notes: string;
    positions: FormPositionLineInput[];
} {
    return {
        client_id: '',
        project_id: '',
        location: '',
        assigned_to: '',
        notification_recipient_ids: [],
        request_received_date: input.today,
        required_by_date: '',
        priority: 'normal',
        notes: '',
        positions: [{ ...input.defaultPositionLine }],
    };
}
