import type { GanttBar } from '../types';

export type RelievedAssignmentMatch = {
    crewAssignmentId: number;
    employeeName: string;
    plannedLeaveDate: string;
};

export function findRelievedAssignment(
    bars: GanttBar[],
    rowKey: string,
    nearDate: string,
): RelievedAssignmentMatch | null {
    const assignedBars = bars
        .filter(
            (bar) =>
                bar.row_key === rowKey &&
                bar.is_assigned &&
                bar.crew_assignment_id !== null &&
                bar.planned_leave_date !== null,
        )
        .sort((a, b) =>
            (a.planned_leave_date ?? '').localeCompare(
                b.planned_leave_date ?? '',
            ),
        );

    if (assignedBars.length === 0) {
        return null;
    }

    const preceding = assignedBars.filter(
        (bar) => (bar.planned_leave_date ?? '') <= nearDate,
    );
    const match =
        preceding.length > 0
            ? preceding[preceding.length - 1]
            : assignedBars[0];

    if (
        match.crew_assignment_id === null ||
        match.planned_leave_date === null
    ) {
        return null;
    }

    return {
        crewAssignmentId: match.crew_assignment_id,
        employeeName: match.employee_name,
        plannedLeaveDate: match.planned_leave_date,
    };
}
