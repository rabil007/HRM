import type { CrewMovementCorrectionFieldValue } from '../../crew-movement-corrections/types';
import type { CorrectablePhase, CrewAssignmentFormOptions } from '../types';

export const CORRECTION_SELECT_OPTIONS: Record<
    string,
    keyof CrewAssignmentFormOptions
> = {
    vessel_id: 'vessels',
    rank_id: 'ranks',
    client_id: 'clients',
    company_visa_type_id: 'visa_types',
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
