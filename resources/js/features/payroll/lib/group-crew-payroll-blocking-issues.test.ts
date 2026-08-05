import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewPayrollBlockingIssue } from './group-crew-payroll-blocking-issues.ts';
import { groupCrewPayrollBlockingIssues } from './group-crew-payroll-blocking-issues.ts';

function issue(
    overrides: Partial<CrewPayrollBlockingIssue> & {
        code: string;
        message: string;
    },
): CrewPayrollBlockingIssue {
    return {
        employee_id: null,
        employee_name: null,
        work_date: null,
        from_date: null,
        to_date: null,
        pay_category: null,
        contract_id: null,
        salary_revision_id: null,
        ...overrides,
    };
}

describe('groupCrewPayrollBlockingIssues', () => {
    it('collapses one issue per work date into a single range summary per employee', () => {
        const issues: CrewPayrollBlockingIssue[] = [
            issue({
                employee_id: 126,
                employee_name: 'AHMAD BARGHOUD',
                code: 'overlapping_historical_contracts',
                message:
                    'Multiple Daily Crew contracts cover work date 2026-07-01.',
                work_date: '2026-07-01',
            }),
            issue({
                employee_id: 126,
                employee_name: 'AHMAD BARGHOUD',
                code: 'overlapping_historical_contracts',
                message:
                    'Multiple Daily Crew contracts cover work date 2026-07-02.',
                work_date: '2026-07-02',
            }),
            issue({
                employee_id: 126,
                employee_name: 'AHMAD BARGHOUD',
                code: 'overlapping_historical_contracts',
                message:
                    'Multiple Daily Crew contracts cover work date 2026-07-03.',
                work_date: '2026-07-03',
            }),
        ];

        const groups = groupCrewPayrollBlockingIssues(issues);

        assert.equal(groups.length, 1);
        assert.equal(groups[0].employeeName, 'AHMAD BARGHOUD');
        assert.equal(groups[0].occurrenceCount, 3);
        assert.equal(
            groups[0].message,
            'Multiple Daily Crew contracts cover work date 2026-07-01 – 2026-07-03 (3 days).',
        );
    });

    it('keeps distinct employees and issue codes as separate groups', () => {
        const issues: CrewPayrollBlockingIssue[] = [
            issue({
                employee_id: 126,
                employee_name: 'AHMAD BARGHOUD',
                code: 'overlapping_historical_contracts',
                message:
                    'Multiple Daily Crew contracts cover work date 2026-07-01.',
                work_date: '2026-07-01',
            }),
            issue({
                employee_id: 355,
                employee_name: 'ANOOP PILLAI',
                code: 'missing_historical_contract',
                message: 'No Daily Crew contract covers work date 2026-06-07.',
                work_date: '2026-06-07',
            }),
        ];

        const groups = groupCrewPayrollBlockingIssues(issues);

        assert.equal(groups.length, 2);
        assert.deepEqual(
            groups.map((group) => group.employeeName),
            ['AHMAD BARGHOUD', 'ANOOP PILLAI'],
        );
    });

    it('leaves single, dateless issues unchanged', () => {
        const issues: CrewPayrollBlockingIssue[] = [
            issue({
                employee_id: 246,
                employee_name: 'FRANKLINE MESAPE EBONG',
                code: 'invalid_approved_timesheet',
                message:
                    'FRANKLINE MESAPE EBONG has Sign-On Standby end date without a start date.',
            }),
        ];

        const groups = groupCrewPayrollBlockingIssues(issues);

        assert.equal(groups.length, 1);
        assert.equal(groups[0].occurrenceCount, 1);
        assert.equal(
            groups[0].message,
            'FRANKLINE MESAPE EBONG has Sign-On Standby end date without a start date.',
        );
    });

    it('shows a single date without a range when only one day is affected', () => {
        const issues: CrewPayrollBlockingIssue[] = [
            issue({
                employee_id: 358,
                employee_name: 'MOHAMAD IMRAN SHAIKH',
                code: 'missing_historical_contract',
                message: 'No Daily Crew contract covers work date 2026-06-22.',
                work_date: '2026-06-22',
            }),
        ];

        const groups = groupCrewPayrollBlockingIssues(issues);

        assert.equal(
            groups[0].message,
            'No Daily Crew contract covers work date 2026-06-22.',
        );
        assert.equal(groups[0].occurrenceCount, 1);
    });
});
