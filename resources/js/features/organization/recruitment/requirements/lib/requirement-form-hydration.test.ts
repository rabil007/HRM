import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { RequirementDetail } from '@/types/recruitment';
import {
    canRemoveRequirementPositionLine,
    hydrateNewRequirementForm,
    hydrateRequirementFormFromDetail,
    nullableDateToFormValue,
    nullableIdToFormValue,
    shouldShowRequirementPositionStructureLockWarning,
} from './requirement-form-hydration.ts';

const defaultPositionLine = {
    position_id: '',
    required_headcount: 1,
    salary_min: '',
    salary_max: '',
    salary_currency_code: 'AED',
    line_notes: '',
};

function makeRequirement(
    overrides: Partial<RequirementDetail> = {},
): RequirementDetail {
    return {
        id: 1,
        requirement_number: 'REQ-2026-000001',
        client_id: null,
        client_name: '—',
        project_id: null,
        project_title: null,
        client_reference_number: null,
        location: null,
        priority: 'normal',
        priority_label: 'Normal',
        priority_badge: 'secondary',
        status: 'draft',
        status_label: 'Draft',
        status_badge: 'secondary',
        assigned_to: null,
        assigned_recruiter_name: null,
        request_received_date: null,
        request_received_date_formatted: '—',
        required_by_date: null,
        required_by_date_formatted: '—',
        deadline_health: null,
        days_remaining_or_overdue: null,
        days_label: '—',
        total_headcount: 0,
        positions_summary: [],
        positions_count: 0,
        repeated_from_id: null,
        repeated_from_number: null,
        next_action: 'submit',
        can_edit: true,
        can_submit: true,
        can_approve: false,
        can_return: false,
        can_resubmit: false,
        can_open: false,
        can_hold: false,
        can_resume: false,
        can_extend: false,
        can_extend_deadline: false,
        can_change_headcount: false,
        can_fill: false,
        can_cancel: false,
        can_reopen: false,
        can_repeat: false,
        can_attach: true,
        notes: 'Partial draft notes',
        lines: [],
        notification_recipients: [],
        ...overrides,
    } as RequirementDetail;
}

describe('requirement form hydration', () => {
    it('maps null ids and dates to empty strings for existing drafts', () => {
        assert.equal(nullableIdToFormValue(null), '');
        assert.equal(nullableIdToFormValue(undefined), '');
        assert.equal(nullableIdToFormValue(12), '12');
        assert.equal(nullableDateToFormValue(null), '');

        const hydrated = hydrateRequirementFormFromDetail({
            requirement: makeRequirement(),
            today: '2026-10-07',
            currencyCode: 'AED',
            defaultPositionLine,
        });

        assert.equal(hydrated.client_id, '');
        assert.equal(hydrated.project_id, '');
        assert.equal(hydrated.assigned_to, '');
        assert.equal(hydrated.request_received_date, '');
        assert.equal(hydrated.required_by_date, '');
        assert.deepEqual(hydrated.positions, []);
    });

    it('does not default request received date to today for existing drafts', () => {
        const hydrated = hydrateRequirementFormFromDetail({
            requirement: makeRequirement({
                request_received_date: null,
            }),
            today: '2026-10-07',
            currencyCode: 'AED',
            defaultPositionLine,
        });

        assert.equal(hydrated.request_received_date, '');
    });

    it('defaults request received date to today for new requirements only', () => {
        const hydrated = hydrateNewRequirementForm({
            today: '2026-10-07',
            defaultPositionLine,
        });

        assert.equal(hydrated.request_received_date, '2026-10-07');
        assert.equal(hydrated.client_id, '');
    });

    it('allows removing the final position line only in Draft/Returned preparation', () => {
        assert.equal(
            canRemoveRequirementPositionLine({
                canEditPositionStructure: true,
                isPreparationEditable: true,
                positionCount: 1,
            }),
            true,
        );
        assert.equal(
            canRemoveRequirementPositionLine({
                canEditPositionStructure: true,
                isPreparationEditable: false,
                positionCount: 1,
            }),
            false,
        );
        assert.equal(
            canRemoveRequirementPositionLine({
                canEditPositionStructure: true,
                isPreparationEditable: false,
                positionCount: 2,
            }),
            true,
        );
        assert.equal(
            canRemoveRequirementPositionLine({
                canEditPositionStructure: false,
                isPreparationEditable: false,
                positionCount: 2,
            }),
            false,
        );
        assert.equal(
            canRemoveRequirementPositionLine({
                canEditPositionStructure: true,
                isPreparationEditable: true,
                positionCount: 0,
            }),
            false,
        );
    });

    it('shows the position lock warning only when titles and headcounts are locked', () => {
        assert.equal(
            shouldShowRequirementPositionStructureLockWarning(true),
            false,
        );
        assert.equal(
            shouldShowRequirementPositionStructureLockWarning(false),
            true,
        );
    });
});
