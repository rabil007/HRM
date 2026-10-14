import type { CrewAssignmentDetail } from '../types.ts';

export type AssignmentLifecycleLabels = {
    started: string;
    ended: string;
};

function isTerminalStatus(status: string): boolean {
    return status === 'completed' || status === 'cancelled';
}

/**
 * Assignment Started / Ended copy for the operational summary card.
 * Uses authoritative assignment timestamps only — never phase or forecast dates.
 */
export function buildAssignmentLifecycleLabels(
    assignment: Pick<
        CrewAssignmentDetail,
        'status' | 'started_at' | 'closed_at'
    >,
    formatDate: (value: string | null | undefined) => string,
): AssignmentLifecycleLabels {
    const started = ((): string => {
        if (assignment.status === 'draft' && !assignment.started_at) {
            return 'Not started';
        }

        if (!assignment.started_at) {
            return assignment.status === 'draft'
                ? 'Not started'
                : 'Not recorded';
        }

        return formatDate(assignment.started_at);
    })();

    const ended = ((): string => {
        if (assignment.status === 'draft') {
            return 'Not started';
        }

        if (assignment.status === 'active') {
            if (assignment.closed_at) {
                return formatDate(assignment.closed_at);
            }

            return 'Ongoing';
        }

        if (isTerminalStatus(assignment.status)) {
            return assignment.closed_at
                ? formatDate(assignment.closed_at)
                : 'Not recorded';
        }

        return assignment.closed_at
            ? formatDate(assignment.closed_at)
            : 'Not recorded';
    })();

    return { started, ended };
}
