import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewPayrollGenerationPreview } from '../types.ts';
import { payrollGenerateReviewCanConfirm } from './payroll-generate-review.ts';

function preview(
    overrides: Partial<CrewPayrollGenerationPreview> = {},
): CrewPayrollGenerationPreview {
    return {
        ready: true,
        can_generate: true,
        ready_count: 1,
        missing_timesheet_count: 0,
        awaiting_approval_count: 0,
        excluded_count: 0,
        blocking_issues: [],
        blocking_count: 0,
        warning_issues: [],
        warning_count: 0,
        skipped_issues: [],
        skipped_count: 0,
        automatic_adjustments: [],
        automatic_adjustment_count: 0,
        applied_preparation_id: null,
        applied_preparation_version: null,
        period_blocking_reason: null,
        blocking_reason: null,
        affected_employee_id: null,
        ...overrides,
    };
}

describe('payrollGenerateReviewCanConfirm', () => {
    it('disables generate when blocking errors exist', () => {
        assert.equal(
            payrollGenerateReviewCanConfirm(
                preview({
                    blocking_count: 1,
                    ready_count: 3,
                    automatic_adjustment_count: 2,
                }),
            ),
            false,
        );
    });

    it('allows generate when only automatic adjustments and skipped exist', () => {
        assert.equal(
            payrollGenerateReviewCanConfirm(
                preview({
                    blocking_count: 0,
                    ready_count: 2,
                    skipped_count: 1,
                    automatic_adjustment_count: 3,
                }),
            ),
            true,
        );
    });

    it('disables generate when nobody is ready', () => {
        assert.equal(
            payrollGenerateReviewCanConfirm(
                preview({
                    blocking_count: 0,
                    ready_count: 0,
                }),
            ),
            false,
        );
    });
});
