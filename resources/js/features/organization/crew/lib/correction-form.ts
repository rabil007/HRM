import type { ActionImpactChange } from '../../../../components/action-impact-preview.ts';
import { formatDisplayDateTime12h } from '../../../../lib/format-date.ts';
import type { CrewMovementCorrectionFieldValue } from '../../crew-movement-corrections/types.ts';
import { correctionFieldLabel } from '../../crew-movement-corrections/types.ts';
import type { CorrectablePhase, CrewAssignmentFormOptions } from '../types.ts';

/** Form option list keys used by correction select fields (excludes scalar metadata). */
export type CorrectionFormOptionListKey =
    | 'vessels'
    | 'ranks'
    | 'clients'
    | 'courses';

export const CORRECTION_SELECT_OPTIONS: Record<
    string,
    CorrectionFormOptionListKey
> = {
    vessel_id: 'vessels',
    rank_id: 'ranks',
    client_id: 'clients',
    'details.course_id': 'courses',
};

export const CORRECTION_DATE_FIELDS = new Set([
    'actual_start_at',
    'actual_end_at',
]);

export function initialCorrectionFieldValue(
    field: string,
    current: CrewMovementCorrectionFieldValue | undefined,
): string {
    if (!current) {
        return '';
    }

    if (CORRECTION_DATE_FIELDS.has(field)) {
        return current.display?.replace(' ', 'T') ?? '';
    }

    if (field in CORRECTION_SELECT_OPTIONS) {
        return current.value == null ? '' : String(current.value);
    }

    return current.display ?? '';
}

export function editableCorrectionFields(phase: CorrectablePhase): string[] {
    const courseId = phase.current_values['details.course_id']?.value;
    const hasStructuredCourse =
        phase.allowed_fields.includes('details.course_id') &&
        typeof courseId === 'number' &&
        courseId > 0;

    return phase.allowed_fields.filter((field) =>
        hasStructuredCourse
            ? field !== 'details.course'
            : field !== 'details.course_id',
    );
}

export function initialCorrectionValues(
    phase: CorrectablePhase,
): Record<string, string> {
    return Object.fromEntries(
        editableCorrectionFields(phase).map((field) => [
            field,
            initialCorrectionFieldValue(field, phase.current_values[field]),
        ]),
    );
}

function formatCorrectionProposedDisplay(
    field: string,
    value: string,
    formOptions?: CrewAssignmentFormOptions,
): string {
    if (!value.trim()) {
        return '—';
    }

    if (CORRECTION_DATE_FIELDS.has(field)) {
        return formatDisplayDateTime12h(value);
    }

    const optionKey = CORRECTION_SELECT_OPTIONS[field];

    if (optionKey && formOptions) {
        const match = formOptions[optionKey]?.find(
            (option) => String(option.id) === value,
        );

        return match?.name ?? value;
    }

    return value;
}

export function buildCorrectionImpactChanges(
    phase: CorrectablePhase,
    proposedValues: Record<string, string>,
    formOptions?: CrewAssignmentFormOptions,
): ActionImpactChange[] {
    return editableCorrectionFields(phase)
        .map((field) => {
            const previous = phase.current_values[field]?.display ?? '—';
            const next = formatCorrectionProposedDisplay(
                field,
                proposedValues[field] ?? '',
                formOptions,
            );
            const initial = initialCorrectionFieldValue(
                field,
                phase.current_values[field],
            );

            if ((proposedValues[field] ?? '') === initial) {
                return null;
            }

            return {
                label: correctionFieldLabel(field),
                previous,
                next,
            };
        })
        .filter((change): change is ActionImpactChange => change !== null);
}
