import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { RequirementIndexRow } from '../../../../../types/recruitment.ts';
import { resolveRequirementWhatsNext } from './requirement-whats-next.ts';

function makeRow(
    overrides: Partial<RequirementIndexRow> = {},
): RequirementIndexRow {
    return {
        id: 1,
        requirement_number: 'REQ-2026-0001',
        client_id: 1,
        client_name: 'NMDC',
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
        total_headcount: 5,
        positions_summary: [],
        positions_count: 1,
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
        can_extend: true,
        can_change_headcount: true,
        can_fill: false,
        can_cancel: true,
        can_reopen: false,
        can_repeat: false,
        ...overrides,
    };
}

describe('requirement whats next', () => {
    it('uses authorized next_action when available', () => {
        const next = resolveRequirementWhatsNext(makeRow());

        assert.equal(next.action, 'submit');
        assert.equal(next.ctaLabel, 'Submit for approval');
    });

    it('falls back when next_action is not authorized', () => {
        const next = resolveRequirementWhatsNext(
            makeRow({
                status: 'open',
                status_label: 'Open',
                next_action: 'fill',
                can_submit: false,
                can_fill: false,
                can_extend: true,
                deadline_health: 'overdue',
                days_label: '2 days overdue',
            }),
        );

        assert.equal(next.action, 'extend');
        assert.match(next.description, /2 days overdue/);
    });

    it('hides CTA when no capability is available', () => {
        const next = resolveRequirementWhatsNext(
            makeRow({
                next_action: 'submit',
                can_edit: false,
                can_submit: false,
                can_extend: false,
                can_cancel: false,
            }),
        );

        assert.equal(next.action, null);
        assert.equal(next.ctaLabel, null);
    });

    it('prefers approve when pending approval', () => {
        const next = resolveRequirementWhatsNext(
            makeRow({
                status: 'pending_approval',
                status_label: 'Pending Approval',
                next_action: 'approve',
                can_submit: false,
                can_approve: true,
            }),
        );

        assert.equal(next.action, 'approve');
    });
});
