import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { PayrollPeriodListItem } from '../types.ts';
import { payrollPeriodMobileCardModel } from './payroll-period-mobile-card.ts';

function period(
    overrides: Partial<PayrollPeriodListItem> = {},
): PayrollPeriodListItem {
    return {
        id: 7,
        name: 'August 2026 Crew',
        start_date: '2026-08-01',
        end_date: '2026-08-31',
        payment_date: null,
        generated_at: null,
        payroll_category: 'crew',
        payroll_category_label: 'Crew',
        crew_timesheet_mode: 'crew_operations',
        crew_timesheet_mode_label: 'Crew operations',
        uses_crew_operations_timesheets: true,
        uses_manual_timesheets: false,
        supports_timesheets: true,
        status: 'draft',
        status_label: 'Draft',
        creation_source: 'manual',
        creation_source_label: 'Manual',
        is_automatic: false,
        notes: null,
        is_editable: true,
        can_generate_crew_payroll: false,
        can_generate_payroll: false,
        can_revert_to_draft: false,
        can_revert_to_approved: false,
        can_revert_to_processing: false,
        can_approve: false,
        can_mark_paid: false,
        can_cancel: false,
        payroll_records_count: 0,
        excluded_employee_ids: [],
        approved_at: null,
        approver: null,
        created_at: '2026-08-01',
        run_label: 'August 2026 Crew',
        employee_count: 18,
        timesheet_eligible_count: 18,
        timesheets_filled_count: 10,
        timesheets_progress_label: '10 / 18 filled',
        ...overrides,
    } as PayrollPeriodListItem;
}

describe('payrollPeriodMobileCardModel', () => {
    it('shows period identity, status, and workflow without salary figures', () => {
        const model = payrollPeriodMobileCardModel(period(), true);

        assert.equal(model.title, 'August 2026 Crew');
        assert.equal(model.categoryLabel, 'Crew');
        assert.equal(model.dateRange, '2026-08-01 – 2026-08-31');
        assert.equal(model.workflowLine, '10 / 18 filled');
        assert.equal(model.status, 'draft');
        assert.equal(model.statusLabel, 'Draft');
        assert.equal(model.showOpen, true);
        assert.equal(model.exposesSalary, false);
    });

    it('hides the open action when the user cannot enter the period', () => {
        const model = payrollPeriodMobileCardModel(period(), false);

        assert.equal(model.showOpen, false);
    });
});
