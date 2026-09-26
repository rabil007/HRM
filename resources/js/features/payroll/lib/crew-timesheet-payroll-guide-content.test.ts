import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    CREW_TIMESHEET_PAYROLL_GUIDE_SUMMARY,
    CREW_TIMESHEET_PAYROLL_GUIDE_TITLE,
    crewTimesheetPayrollGuideSections,
} from './crew-timesheet-payroll-guide-content.ts';

describe('crewTimesheetPayrollGuideSections', () => {
    it('exposes a safe operational guide without salary amounts', () => {
        const sections = crewTimesheetPayrollGuideSections();
        const text = [
            CREW_TIMESHEET_PAYROLL_GUIDE_TITLE,
            CREW_TIMESHEET_PAYROLL_GUIDE_SUMMARY,
            ...sections.flatMap((section) => [
                section.title,
                section.body,
                ...(section.bullets ?? []),
                section.callout ?? '',
            ]),
        ].join(' ');

        assert.match(CREW_TIMESHEET_PAYROLL_GUIDE_TITLE, /Crew Timesheet/i);
        assert.equal(sections.length >= 6, true);
        assert.match(text, /Populate \/ Refresh from Crew Assignments/i);
        assert.match(text, /do not modify the original Crew Assignment/i);
        assert.match(text, /prior-period work \/ arrears/i);
        assert.match(text, /Clear Manual\/Imported Timesheets/i);
        assert.doesNotMatch(text, /AED|\$|rate amount|basic_salary/i);
    });
});
