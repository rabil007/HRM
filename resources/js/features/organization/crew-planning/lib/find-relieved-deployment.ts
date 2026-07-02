import type { GanttBar } from '../types';

export type RelievedDeploymentMatch = {
    employeeDeploymentId: number;
    employeeName: string;
    plannedLeaveDate: string;
};

/**
 * Find the deployed crew on a rank row that a relief assignment should replace.
 */
export function findRelievedDeployment(
    bars: GanttBar[],
    rowKey: string,
    nearDate: string,
): RelievedDeploymentMatch | null {
    const deployedBars = bars
        .filter(
            (bar) =>
                bar.row_key === rowKey &&
                bar.is_deployed &&
                bar.employee_deployment_id !== null,
        )
        .sort((a, b) =>
            a.planned_leave_date.localeCompare(b.planned_leave_date),
        );

    if (deployedBars.length === 0) {
        return null;
    }

    const preceding = deployedBars.filter(
        (bar) => bar.planned_leave_date <= nearDate,
    );
    const match =
        preceding.length > 0
            ? preceding[preceding.length - 1]
            : deployedBars[0];

    if (match.employee_deployment_id === null) {
        return null;
    }

    return {
        employeeDeploymentId: match.employee_deployment_id,
        employeeName: match.employee_name,
        plannedLeaveDate: match.planned_leave_date,
    };
}
