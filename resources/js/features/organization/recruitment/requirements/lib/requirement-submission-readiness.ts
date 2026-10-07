import type { FormPositionLineInput } from '../types';
import { validateSalaryRange } from './requirement-salary.ts';

export type SubmissionReadinessItem = {
    key: string;
    label: string;
    ready: boolean;
    message: string | null;
};

export type SubmissionReadinessSummary = {
    ready: boolean;
    remaining_count: number;
    items: SubmissionReadinessItem[];
};

type PositionTitleLookup = Record<string, string>;

function item(
    key: string,
    label: string,
    ready: boolean,
    message: string | null,
): SubmissionReadinessItem {
    return {
        key,
        label,
        ready,
        message: ready ? null : message,
    };
}

function salaryReady(
    min: string | number | '' | undefined,
    max: string | number | '' | undefined,
): boolean {
    if (min === undefined || min === null || min === '') {
        return false;
    }

    if (max === undefined || max === null || max === '') {
        return false;
    }

    const minNum = Number(min);
    const maxNum = Number(max);

    if (!Number.isFinite(minNum) || !Number.isFinite(maxNum)) {
        return false;
    }

    if (minNum < 0 || maxNum < 0 || maxNum < minNum) {
        return false;
    }

    const minStr = String(min);
    const maxStr = String(max);

    return /^\d+(\.\d{1,2})?$/.test(minStr) && /^\d+(\.\d{1,2})?$/.test(maxStr);
}

/**
 * Client-side submission readiness for the create/edit form.
 * Server-side RequirementSubmissionReadiness remains authoritative.
 */
export function evaluateRequirementFormSubmissionReadiness(input: {
    clientId: string | number | '';
    requestReceivedDate: string;
    requiredByDate: string;
    assignedTo: string | number | '';
    positions: FormPositionLineInput[];
    positionTitles?: PositionTitleLookup;
    creatorUserId?: number | null;
}): SubmissionReadinessSummary {
    const items: SubmissionReadinessItem[] = [];

    items.push(
        item(
            'client',
            'Client selected',
            Boolean(input.clientId),
            'Select a client.',
        ),
    );

    items.push(
        item(
            'request_received_date',
            'Request received date',
            Boolean(input.requestReceivedDate),
            'Enter the Request Received from Client date.',
        ),
    );

    items.push(
        item(
            'required_by_date',
            'Required-by date',
            Boolean(input.requiredByDate),
            'Enter the required-by date.',
        ),
    );

    const datesOrdered =
        !input.requestReceivedDate ||
        !input.requiredByDate ||
        input.requiredByDate >= input.requestReceivedDate;

    items.push(
        item(
            'required_by_date_order',
            'Required-by date order',
            datesOrdered,
            'Required-by date must be on or after the Request Received from Client date.',
        ),
    );

    items.push(
        item(
            'assigned_recruiter',
            'Assigned recruiter',
            Boolean(input.assignedTo),
            'Assign an approving recruiter.',
        ),
    );

    const selfAssigned =
        input.creatorUserId != null &&
        input.assignedTo !== '' &&
        Number(input.assignedTo) === Number(input.creatorUserId);

    items.push(
        item(
            'self_approval',
            'Requester and recruiter are different',
            !selfAssigned,
            'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
        ),
    );

    const activePositions = input.positions.filter((line) =>
        Boolean(line.position_id),
    );

    items.push(
        item(
            'active_positions',
            'At least one position line',
            activePositions.length > 0,
            'At least one active position line is required.',
        ),
    );

    activePositions.forEach((line, index) => {
        const positionKey = String(line.position_id);
        const title =
            input.positionTitles?.[positionKey] ?? `Position ${index + 1}`;
        const headcount = Number(line.required_headcount);
        const headcountReady = Number.isInteger(headcount) && headcount >= 1;

        items.push(
            item(
                `headcount_line_${line.id ?? positionKey}`,
                `Headcount for ${title}`,
                headcountReady,
                headcountReady
                    ? null
                    : `Enter a required headcount of at least 1 for ${title}.`,
            ),
        );

        const ready = salaryReady(line.salary_min, line.salary_max);

        items.push(
            item(
                `salary_line_${line.id ?? positionKey}`,
                `Salary range for ${title}`,
                ready,
                ready ? null : `Complete a valid salary range for ${title}.`,
            ),
        );
    });

    const remaining = items.filter((entry) => !entry.ready);

    return {
        ready: remaining.length === 0,
        remaining_count: remaining.length,
        items,
    };
}

export function incompleteSubmissionMessages(
    summary: SubmissionReadinessSummary,
): string[] {
    return summary.items
        .filter((entry) => !entry.ready && entry.message)
        .map((entry) => entry.message as string);
}

export function isSubmissionReadinessComplete(
    summary: SubmissionReadinessSummary | null | undefined,
): boolean {
    return summary?.ready === true;
}

/**
 * Whether the form should expose Create/Save & Submit / Save & Resubmit.
 * Backend still authorizes the submit permission.
 */
export function canShowRequirementSubmitFormAction(
    canSubmitPermission: boolean,
): boolean {
    return canSubmitPermission;
}

function resolveSalaryReadinessFieldErrors(
    line: FormPositionLineInput,
    index: number,
    fallbackMessage: string,
): Record<string, string> {
    const validation = validateSalaryRange(
        line.salary_min,
        line.salary_max,
        true,
    );
    const mapped: Record<string, string> = {};

    if (validation.minError) {
        mapped[`positions.${index}.salary_min`] = validation.minError;
    }

    if (validation.maxError) {
        mapped[`positions.${index}.salary_max`] = validation.maxError;
    }

    if (Object.keys(mapped).length === 0) {
        mapped[`positions.${index}.salary_min`] = fallbackMessage;
    }

    return mapped;
}

function resolveReadinessLineMatch(
    entryKey: string,
    prefix: 'salary_line_' | 'headcount_line_',
    positions: FormPositionLineInput[],
): { line: FormPositionLineInput; index: number } | null {
    const token = entryKey.slice(prefix.length);
    const activeIndexes = positions
        .map((line, index) => ({ line, index }))
        .filter(({ line }) => Boolean(line.position_id));

    return (
        activeIndexes.find(
            ({ line, index }) =>
                String(line.id ?? '') === token ||
                String(line.position_id) === token ||
                String(index) === token,
        ) ?? null
    );
}

/**
 * Map readiness failures to Inertia/form field keys for inline highlighting.
 */
export function readinessToFormFieldErrors(
    summary: SubmissionReadinessSummary,
    positions: FormPositionLineInput[] = [],
): Record<string, string> {
    const errors: Record<string, string> = {};

    for (const entry of summary.items) {
        if (entry.ready || !entry.message) {
            continue;
        }

        if (entry.key.startsWith('salary_line_')) {
            const matched = resolveReadinessLineMatch(
                entry.key,
                'salary_line_',
                positions,
            );

            if (!matched) {
                if (!errors.positions) {
                    errors.positions = entry.message;
                }

                continue;
            }

            const salaryErrors = resolveSalaryReadinessFieldErrors(
                matched.line,
                matched.index,
                entry.message,
            );

            for (const [field, message] of Object.entries(salaryErrors)) {
                if (!errors[field]) {
                    errors[field] = message;
                }
            }

            continue;
        }

        if (entry.key.startsWith('headcount_line_')) {
            const matched = resolveReadinessLineMatch(
                entry.key,
                'headcount_line_',
                positions,
            );

            const field = matched
                ? `positions.${matched.index}.required_headcount`
                : 'positions';

            if (!errors[field]) {
                errors[field] = entry.message;
            }

            continue;
        }

        let field: string =
            {
                client: 'client_id',
                request_received_date: 'request_received_date',
                required_by_date: 'required_by_date',
                required_by_date_order: 'required_by_date',
                assigned_recruiter: 'assigned_to',
                assigned_recruiter_eligible: 'assigned_to',
                self_approval: 'assigned_to',
                active_positions: 'positions',
            }[entry.key] ?? 'status';

        if (entry.key === 'active_positions') {
            const emptyRowIndex = positions.findIndex(
                (line) => !line.position_id,
            );

            if (emptyRowIndex >= 0) {
                field = `positions.${emptyRowIndex}.position_id`;
            }
        }

        if (!errors[field]) {
            errors[field] = entry.message;
        }
    }

    return errors;
}

export function compactSubmissionAttentionLabel(
    remainingCount: number,
): string {
    if (remainingCount <= 0) {
        return 'Ready for approval';
    }

    return `${remainingCount} item${remainingCount === 1 ? '' : 's'} need attention before this requirement can be submitted.`;
}
