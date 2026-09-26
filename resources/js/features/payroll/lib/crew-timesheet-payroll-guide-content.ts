/**
 * Informational copy for the Crew payroll period Timesheet guide.
 * Safe for Crew Timesheet-only users — no salary amounts or rates.
 */

export type CrewTimesheetPayrollGuideSection = {
    title: string;
    body: string;
    bullets?: string[];
    callout?: string;
};

export const CREW_TIMESHEET_PAYROLL_GUIDE_TITLE =
    'How Crew Timesheet & Payroll Works';

export const CREW_TIMESHEET_PAYROLL_GUIDE_SUMMARY =
    'Record movements in Crew Assignments, populate the Draft payroll period, review corrections, then generate. Payroll corrections never rewrite Crew Assignment history.';

export const CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL = 'Guide';

export function crewTimesheetPayrollGuideSections(): CrewTimesheetPayrollGuideSection[] {
    return [
        {
            title: '1. Record movements',
            body: 'Crew Operations records the employee’s real movement in Crew Assignments. Crew Assignments remain the official operational history.',
            bullets: [
                'Sign-On Standby',
                'Onsite / On Vessel',
                'Sign-Off Standby',
            ],
        },
        {
            title: '2. Populate payroll',
            body: 'Open the Draft Crew payroll period and use “Populate / Refresh from Crew Assignments”. Movements are copied into the payroll timesheet working data for this pay run.',
        },
        {
            title: '3. Review and correct',
            body: 'Authorized users may correct payroll-specific Sign-On Standby, Onsite / On Vessel, and Sign-Off Standby dates, multiple movement periods, OT hours, and remarks.',
            callout:
                'Payroll corrections affect this payroll only. They do not modify the original Crew Assignment or movement history.',
        },
        {
            title: '4. Prior-period dates / arrears',
            body: 'A September payroll may include dates such as 25–31 Aug (Standby) and 01–20 Sep (Onsite). For Daily Crew, dates before the payroll period are treated as prior-period work / arrears.',
            bullets: [
                'Contract and salary rates are resolved using the actual work date',
                'August dates use the contract/rate effective in August',
                'September dates use the September-effective contract/rate',
                'Already-paid historical dates are excluded so they are not paid twice',
                'Dates reserved by another open payroll block generation',
                'Missing historical contract or rates block generation',
            ],
        },
        {
            title: '5. Generate payroll',
            body: 'Before generation the system validates timesheets and tells you what must be corrected.',
            bullets: [
                'Red — Blocking errors: must be fixed before payroll can be generated',
                'Amber — Warnings / skipped employees: generation may continue where appropriate, but you must be informed',
                'Blue / neutral — Automatic payroll decisions: the system handled something automatically, and you can still see what happened',
            ],
        },
        {
            title: '6. Clear Timesheets',
            body: 'Clear Manual/Imported Timesheets does not delete Crew Assignments or official movement history. Crew Operations-sourced movement data is not the same as manually entered or imported payroll timesheet data.',
        },
    ];
}
