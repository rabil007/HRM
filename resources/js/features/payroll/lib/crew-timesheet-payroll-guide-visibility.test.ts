import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL } from './crew-timesheet-payroll-guide-content.ts';
import { shouldShowCrewTimesheetPayrollGuide } from './crew-timesheet-payroll-guide-visibility.ts';

describe('shouldShowCrewTimesheetPayrollGuide', () => {
    it('never shows on the Payroll index', () => {
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-index',
                payrollCategory: 'crew',
            }),
            false,
        );
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-index',
                payrollCategory: 'office',
            }),
            false,
        );
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-index',
            }),
            false,
        );
    });

    it('shows on Crew payroll period detail', () => {
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-show',
                payrollCategory: 'crew',
            }),
            true,
        );
    });

    it('does not show on Office payroll period detail', () => {
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-show',
                payrollCategory: 'office',
            }),
            false,
        );
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-show',
                payrollCategory: null,
            }),
            false,
        );
        assert.equal(
            shouldShowCrewTimesheetPayrollGuide({
                surface: 'payroll-show',
            }),
            false,
        );
    });
});

describe('CrewTimesheetPayrollGuide compact trigger', () => {
    it('uses the lightweight Guide label for the Timesheets header', () => {
        assert.equal(CREW_TIMESHEET_PAYROLL_GUIDE_TRIGGER_LABEL, 'Guide');
    });
});
