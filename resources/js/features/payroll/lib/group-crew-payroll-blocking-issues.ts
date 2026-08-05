import type { CrewPayrollGenerationPreview } from '../types';

export type CrewPayrollBlockingIssue =
    CrewPayrollGenerationPreview['blocking_issues'][number];

export type CrewPayrollBlockingIssueGroup = {
    key: string;
    employeeId: number | null;
    employeeName: string | null;
    code: string;
    message: string;
    occurrenceCount: number;
};

const DATE_PLACEHOLDER = '{{date}}';

/**
 * Collapses per-work-date blocking issues (one entry per affected calendar
 * day) into a single line per employee/issue, with the individual dates
 * replaced by a compact range summary. Without this, an employee affected on
 * every day of the period would flood the list and hide every other blocked
 * employee.
 */
export function groupCrewPayrollBlockingIssues(
    issues: CrewPayrollBlockingIssue[],
): CrewPayrollBlockingIssueGroup[] {
    const groups = new Map<
        string,
        {
            employeeId: number | null;
            employeeName: string | null;
            code: string;
            template: string;
            dates: string[];
        }
    >();

    issues.forEach((issue) => {
        const workDate =
            issue.work_date ?? issue.to_date ?? issue.from_date ?? null;
        const template =
            workDate && issue.message.includes(workDate)
                ? issue.message.split(workDate).join(DATE_PLACEHOLDER)
                : issue.message;
        const key = `${issue.employee_id ?? 'period'}::${issue.code}::${template}`;
        const existing = groups.get(key);

        if (existing) {
            if (workDate) {
                existing.dates.push(workDate);
            }

            return;
        }

        groups.set(key, {
            employeeId: issue.employee_id,
            employeeName: issue.employee_name,
            code: issue.code,
            template,
            dates: workDate ? [workDate] : [],
        });
    });

    return Array.from(groups.entries()).map(([key, group]) => {
        const dates = Array.from(new Set(group.dates)).sort();
        const rangeText =
            dates.length === 0
                ? null
                : dates.length === 1
                  ? dates[0]
                  : `${dates[0]} – ${dates[dates.length - 1]} (${dates.length} days)`;

        return {
            key,
            employeeId: group.employeeId,
            employeeName: group.employeeName,
            code: group.code,
            message: rangeText
                ? group.template.split(DATE_PLACEHOLDER).join(rangeText)
                : group.template,
            occurrenceCount: Math.max(dates.length, 1),
        };
    });
}
