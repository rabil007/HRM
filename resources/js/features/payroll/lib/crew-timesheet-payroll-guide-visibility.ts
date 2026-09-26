import type { PayrollCategory } from '../types';

export type CrewTimesheetPayrollGuideSurface = 'payroll-index' | 'payroll-show';

/**
 * The Crew Timesheet guide is informational only and belongs on the Crew
 * payroll period detail page — never on the Payroll index or Office periods.
 */
export function shouldShowCrewTimesheetPayrollGuide(args: {
    surface: CrewTimesheetPayrollGuideSurface;
    payrollCategory?: PayrollCategory | null;
}): boolean {
    if (args.surface !== 'payroll-show') {
        return false;
    }

    return args.payrollCategory === 'crew';
}
