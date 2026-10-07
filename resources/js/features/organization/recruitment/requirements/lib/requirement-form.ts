import type { FormPositionLineInput } from '../types';

export const REQUIREMENT_FORM_FIELD_ORDER = [
    'client_id',
    'project_id',
    'location',
    'request_received_date',
    'required_by_date',
    'priority',
    'assigned_to',
    'notification_recipient_ids',
    'positions',
    'notes',
    'attachment',
] as const;

export type RequirementFormFieldKey =
    (typeof REQUIREMENT_FORM_FIELD_ORDER)[number];

const POSITION_LINE_ATTRIBUTE_ORDER = [
    'position_id',
    'required_headcount',
    'salary_min',
    'salary_max',
] as const;

export type RequirementPositionLineAttribute =
    (typeof POSITION_LINE_ATTRIBUTE_ORDER)[number];

export function parseRequirementPositionFieldError(
    key: string,
): { index: number; attribute: string; prefix: 'positions' | 'lines' } | null {
    const match = /^(positions|lines)\.(\d+)\.([a-z_]+)$/.exec(key);

    if (!match) {
        return null;
    }

    return {
        prefix: match[1] as 'positions' | 'lines',
        index: Number(match[2]),
        attribute: match[3],
    };
}

export function requirementFieldError(
    errors: Record<string, string | string[] | undefined>,
    field: string,
    index?: number,
    attribute?: string,
): string | undefined {
    const direct = errors[field];

    if (typeof direct === 'string') {
        return direct;
    }

    if (index === undefined || attribute === undefined) {
        return undefined;
    }

    const positionsKey = `positions.${index}.${attribute}`;
    const linesKey = `lines.${index}.${attribute}`;
    const nested = errors[positionsKey] ?? errors[linesKey] ?? undefined;

    return typeof nested === 'string' ? nested : undefined;
}

function findFirstNestedPositionError(
    errors: Record<string, string | string[] | undefined>,
): string | null {
    const keys = Object.keys(errors).filter((key) => {
        if (!errors[key]) {
            return false;
        }

        return key.startsWith('positions.') || key.startsWith('lines.');
    });

    if (keys.length === 0) {
        return null;
    }

    keys.sort((left, right) => {
        const parsedLeft = parseRequirementPositionFieldError(left);
        const parsedRight = parseRequirementPositionFieldError(right);

        if (!parsedLeft || !parsedRight) {
            return left.localeCompare(right);
        }

        if (parsedLeft.index !== parsedRight.index) {
            return parsedLeft.index - parsedRight.index;
        }

        const leftOrder = POSITION_LINE_ATTRIBUTE_ORDER.indexOf(
            parsedLeft.attribute as RequirementPositionLineAttribute,
        );
        const rightOrder = POSITION_LINE_ATTRIBUTE_ORDER.indexOf(
            parsedRight.attribute as RequirementPositionLineAttribute,
        );

        return (
            (leftOrder === -1 ? 99 : leftOrder) -
            (rightOrder === -1 ? 99 : rightOrder)
        );
    });

    return keys[0] ?? null;
}

export type RequirementFormSnapshot = {
    client_id: string;
    project_id: string;
    location: string;
    assigned_to: string;
    notification_recipient_ids: number[];
    request_received_date: string;
    required_by_date: string;
    priority: string;
    notes: string;
    positions: Array<{
        id?: number;
        position_id: string;
        required_headcount: number;
        salary_min: string;
        salary_max: string;
        line_notes: string;
    }>;
    hasAttachment: boolean;
};

function normalizePositions(positions: FormPositionLineInput[]): Array<{
    id?: number;
    position_id: string;
    required_headcount: number;
    salary_min: string;
    salary_max: string;
    line_notes: string;
}> {
    return positions.map((line) => ({
        id: line.id,
        position_id: String(line.position_id ?? ''),
        required_headcount: Number(line.required_headcount) || 0,
        salary_min:
            line.salary_min !== undefined && line.salary_min !== null
                ? String(line.salary_min)
                : '',
        salary_max:
            line.salary_max !== undefined && line.salary_max !== null
                ? String(line.salary_max)
                : '',
        line_notes: line.line_notes ?? '',
    }));
}

export function dedupeNotificationRecipientIds(ids: number[]): number[] {
    return [...new Set(ids.filter((id) => Number.isInteger(id) && id > 0))];
}

export function createRequirementFormSnapshot(input: {
    client_id: string | number;
    project_id: string | number | '';
    location: string;
    assigned_to: string | number | '';
    notification_recipient_ids?: number[];
    request_received_date: string;
    required_by_date: string;
    priority: string;
    notes: string;
    positions: FormPositionLineInput[];
    attachment?: File | null;
}): RequirementFormSnapshot {
    return {
        client_id: String(input.client_id ?? ''),
        project_id: String(input.project_id ?? ''),
        location: input.location ?? '',
        assigned_to: String(input.assigned_to ?? ''),
        notification_recipient_ids: dedupeNotificationRecipientIds(
            input.notification_recipient_ids ?? [],
        ),
        request_received_date: input.request_received_date ?? '',
        required_by_date: input.required_by_date ?? '',
        priority: input.priority ?? 'normal',
        notes: input.notes ?? '',
        positions: normalizePositions(input.positions),
        hasAttachment: Boolean(input.attachment),
    };
}

export function isRequirementFormDirty(
    baseline: RequirementFormSnapshot | null,
    current: RequirementFormSnapshot,
): boolean {
    if (!baseline) {
        return false;
    }

    return JSON.stringify(baseline) !== JSON.stringify(current);
}

export function firstInvalidRequirementField(
    errors: Record<string, string | string[] | undefined>,
): string | null {
    for (const field of REQUIREMENT_FORM_FIELD_ORDER) {
        if (field === 'positions') {
            if (errors.positions) {
                return 'positions';
            }

            const nested = findFirstNestedPositionError(errors);

            if (nested) {
                return nested;
            }

            continue;
        }

        if (errors[field]) {
            return field;
        }
    }

    const nestedRecipientError = Object.keys(errors).find((key) =>
        key.startsWith('notification_recipient_ids'),
    );

    if (nestedRecipientError) {
        return 'notification_recipient_ids';
    }

    return null;
}

export function requirementFormFieldSelector(field: string): string {
    const nested = parseRequirementPositionFieldError(field);

    if (nested) {
        const { index, attribute } = nested;

        return `[data-requirement-field="positions.${index}.${attribute}"], [data-requirement-field="lines.${index}.${attribute}"]`;
    }

    if (field === 'positions') {
        return '[data-requirement-field="positions"]';
    }

    if (field === 'notification_recipient_ids') {
        return '[data-requirement-field="notification_recipient_ids"]';
    }

    if (field === 'attachment') {
        return '#attachment';
    }

    return `#${field}, [data-requirement-field="${field}"]`;
}

/**
 * Resolves whether "Create separate requirement" should submit for approval,
 * based on the intent captured before the duplicate dialog opened.
 */
export function resolveDuplicateCreateSeparateIntent(
    pendingSubmitForApproval: boolean,
): boolean {
    return pendingSubmitForApproval;
}

export type DuplicateDialogDecision =
    | 'create_separate'
    | 'return_and_review'
    | 'dismiss';

/**
 * After a duplicate-dialog decision, returns whether submit-for-approval should
 * be applied and whether the pending intent must be cleared.
 */
export function resolveDuplicateDialogSubmitIntent(
    pendingSubmitForApproval: boolean,
    decision: DuplicateDialogDecision,
): { submitForApproval: boolean; clearPendingIntent: boolean } {
    if (decision === 'create_separate') {
        return {
            submitForApproval: pendingSubmitForApproval,
            clearPendingIntent: true,
        };
    }

    return {
        submitForApproval: false,
        clearPendingIntent: true,
    };
}
