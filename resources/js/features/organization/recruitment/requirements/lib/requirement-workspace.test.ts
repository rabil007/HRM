import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { RequirementFilters } from '../../../../../types/recruitment.ts';
import { visibleRequirementActions } from './requirement-actions.ts';
import {
    buildRequirementActiveFilterChips,
    clearAllRequirementFilters,
    hasRequirementActiveFilters,
} from './requirement-active-filters.ts';
import { resolveRequirementEmptyState } from './requirement-empty-state.ts';
import { resolveRequirementRepeatFieldGroups } from './requirement-repeat-copy.ts';

const filters: RequirementFilters = {
    tab: 'active',
    search: 'rigger',
    client_id: 12,
    project_id: null,
    position_id: null,
    assigned_to: null,
    priority: 'urgent',
    deadline_health: 'overdue',
    per_page: 15,
};

describe('requirement workspace helpers', () => {
    it('builds active filter chips and clear-all payload', () => {
        const chips = buildRequirementActiveFilterChips(
            filters,
            {
                clients: [{ id: 12, name: 'NMDC', is_active: true }],
                projects: [],
                positions: [],
                recruiters: [],
            },
            'rigger',
        );

        assert.deepEqual(
            chips.map((chip) => chip.key),
            ['search', 'client_id', 'priority', 'deadline_health'],
        );
        assert.equal(hasRequirementActiveFilters(filters, 'rigger'), true);
        assert.deepEqual(clearAllRequirementFilters(), {
            client_id: null,
            project_id: null,
            position_id: null,
            assigned_to: null,
            priority: null,
            deadline_health: null,
            search: '',
        });
    });

    it('distinguishes empty and filtered-empty copy', () => {
        assert.equal(
            resolveRequirementEmptyState({
                activeTab: 'active',
                hasSearch: true,
                hasActiveFilters: false,
                canCreate: true,
            }).showClearAction,
            true,
        );
        assert.equal(
            resolveRequirementEmptyState({
                activeTab: 'active',
                hasSearch: false,
                hasActiveFilters: false,
                canCreate: true,
            }).showCreateAction,
            true,
        );
        assert.equal(
            resolveRequirementEmptyState({
                activeTab: 'on_hold',
                hasSearch: false,
                hasActiveFilters: false,
                canCreate: true,
            }).showCreateAction,
            false,
        );
    });

    it('exposes only authorized actions for menus', () => {
        const actions = visibleRequirementActions({
            id: 1,
            requirement_number: 'REQ-1',
            client_id: 1,
            client_name: 'Client',
            project_id: null,
            project_title: null,
            client_reference_number: null,
            location: null,
            priority: 'normal',
            priority_label: 'Normal',
            priority_badge: 'secondary',
            status: 'open',
            status_label: 'Open',
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
            total_headcount: 1,
            positions_summary: [],
            positions_count: 1,
            repeated_from_id: null,
            repeated_from_number: null,
            next_action: 'fill',
            can_edit: true,
            can_submit: false,
            can_approve: false,
            can_return: false,
            can_resubmit: false,
            can_open: false,
            can_hold: true,
            can_resume: false,
            can_extend: true,
            can_change_headcount: false,
            can_fill: true,
            can_cancel: false,
            can_reopen: false,
            can_repeat: false,
        });

        assert.equal(actions.canEdit, true);
        assert.equal(actions.canHold, true);
        assert.equal(actions.canCancel, false);
        assert.equal(actions.canFill, true);
    });

    it('documents which fields are copied versus reviewed on repeat', () => {
        const groups = resolveRequirementRepeatFieldGroups();

        assert.ok(groups.copied.fields.includes('Client and project'));
        assert.ok(groups.review.fields.includes('Required-by date'));
        assert.equal(
            groups.review.fields.includes('Client reference number'),
            false,
        );
        assert.match(groups.note, /new draft/i);
    });
});
