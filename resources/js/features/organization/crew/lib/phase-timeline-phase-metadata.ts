import type { PhaseTimelineItem } from '../types.ts';

export type PhaseMetadataRow = {
    key: string;
    label: string;
    value: string;
};

const P2B_HANDLED_KEYS = new Set(['course', 'provider', 'course_id']);

const DETAIL_LABELS: Record<string, string> = {
    provider: 'Training Provider',
    course: 'Course',
    location: 'Location',
    certificate_no: 'Certificate No.',
    certificate_number: 'Certificate No.',
    institute: 'Institute',
    institute_center: 'Institute',
    remarks: 'Remarks',
};

function humanizeDetailKey(key: string): string {
    const mapped = DETAIL_LABELS[key];

    if (mapped) {
        return mapped;
    }

    return key
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function isInternalDetailKey(key: string): boolean {
    if (key === 'course_id' || key.endsWith('_id')) {
        return true;
    }

    return false;
}

function formatDetailValue(value: unknown): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();

        return trimmed === '' ? null : trimmed;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    return null;
}

function readDetailString(
    details: Record<string, unknown>,
    key: string,
): string | null {
    return formatDetailValue(details[key]);
}

/**
 * Operator-facing metadata rows for phase cards (no raw keys or internal IDs).
 */
export function buildPhaseMetadataRows(
    phase: PhaseTimelineItem,
): PhaseMetadataRow[] {
    if (!phase.details || typeof phase.details !== 'object') {
        return [];
    }

    const details = phase.details as Record<string, unknown>;
    const rows: PhaseMetadataRow[] = [];
    const usedKeys = new Set<string>();

    if (phase.phase_code === 'p2b') {
        const course = readDetailString(details, 'course');

        if (course) {
            rows.push({ key: 'course', label: 'Course', value: course });
            usedKeys.add('course');
        }

        const provider = readDetailString(details, 'provider');

        if (provider) {
            rows.push({
                key: 'provider',
                label: 'Training Provider',
                value: provider,
            });
            usedKeys.add('provider');
        }
    }

    for (const [key, rawValue] of Object.entries(details)) {
        if (usedKeys.has(key) || P2B_HANDLED_KEYS.has(key)) {
            continue;
        }

        if (isInternalDetailKey(key)) {
            continue;
        }

        const value = formatDetailValue(rawValue);

        if (!value) {
            continue;
        }

        rows.push({
            key,
            label: humanizeDetailKey(key),
            value,
        });
    }

    return rows;
}
