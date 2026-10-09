import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import type { VoidImpactPreview } from '../types.ts';
import {
    accommodationSummaryLabel,
    collectProtectedBlockers,
    hasLinkedRecordsForCleanup,
    isCleanupEligibleBlocker,
    unresolvedCleanupRequired,
} from './void-impact-preview.ts';

const basePreview = {
    total_assignments: 1,
    total_sea_service_records: 0,
    total_training_records: 0,
    total_draft_timesheet_segments: 2,
    total_draft_preparation_lines: 0,
    total_draft_timesheet_periods: 1,
    total_accommodation_records: 1,
    can_delete_sea_service: true,
    can_delete_training: true,
    can_delete_draft_timesheet: true,
    can_delete_accommodation: true,
    has_sea_service: false,
    has_training: false,
    has_draft_timesheet: true,
    has_accommodation: true,
    has_protected_blockers: false,
    blocked_assignment_nos: [],
    assignments: [
        {
            id: 99,
            assignment_no: 'CA-2026-000099',
            employee_name: 'Test Crew',
            current_phase: null,
            sea_service_count: 0,
            training_count: 0,
            blockers: [
                {
                    code: 'draft_timesheet_exists',
                    message:
                        'Assignment CA-2026-000099 has Draft timesheet data. Select Remove linked Draft timesheet data to continue.',
                },
                {
                    code: 'accommodation_history_exists',
                    message:
                        'This assignment has 1 accommodation record. Select Delete linked accommodation records to continue.',
                },
            ],
            protected_blockers: [],
            cleanup_blockers: [
                {
                    code: 'draft_timesheet_exists',
                    message:
                        'Assignment CA-2026-000099 has Draft timesheet data. Select Remove linked Draft timesheet data to continue.',
                },
                {
                    code: 'accommodation_history_exists',
                    message:
                        'This assignment has 1 accommodation record. Select Delete linked accommodation records to continue.',
                },
            ],
            has_protected_blockers: false,
            has_sea_service: false,
            has_draft_timesheet: true,
            has_accommodation: true,
            draft_timesheet: {
                segment_count: 2,
                preparation_line_count: 0,
                period_count: 1,
                periods: [{ id: 1, name: 'Oct Draft', status: 'draft' }],
            },
            accommodation: {
                record_count: 1,
                open_count: 1,
                summaries: [
                    {
                        id: 1,
                        stay_type: 'pre_join',
                        accommodation_status: 'hotel',
                        hotel_name: 'Royal Rose',
                        is_open: true,
                    },
                ],
            },
            dependent_assignments: [],
        },
    ],
} satisfies VoidImpactPreview;

describe('void-impact-preview helpers', () => {
    it('recognises cleanup-eligible blocker codes only', () => {
        assert.equal(isCleanupEligibleBlocker('draft_timesheet_exists'), true);
        assert.equal(
            isCleanupEligibleBlocker('accommodation_history_exists'),
            true,
        );
        assert.equal(isCleanupEligibleBlocker('sea_service_exists'), true);
        assert.equal(isCleanupEligibleBlocker('payroll_protected'), false);
        assert.equal(
            isCleanupEligibleBlocker('linked_assignment_exists'),
            false,
        );
    });

    it('keeps delete disabled until all cleanup options are selected', () => {
        assert.equal(
            unresolvedCleanupRequired(basePreview, {
                delete_sea_service: false,
                delete_draft_timesheet: false,
                delete_accommodation: false,
            }),
            true,
        );

        assert.equal(
            unresolvedCleanupRequired(basePreview, {
                delete_sea_service: false,
                delete_draft_timesheet: true,
                delete_accommodation: false,
            }),
            true,
        );

        assert.equal(
            unresolvedCleanupRequired(basePreview, {
                delete_sea_service: false,
                delete_draft_timesheet: true,
                delete_accommodation: true,
            }),
            false,
        );
    });

    it('collects exact protected backend messages grouped by assignment', () => {
        const protectedPreview: VoidImpactPreview = {
            ...basePreview,
            has_protected_blockers: true,
            blocked_assignment_nos: ['CA-2026-000099'],
            has_draft_timesheet: false,
            has_accommodation: false,
            total_draft_timesheet_segments: 0,
            total_draft_preparation_lines: 0,
            total_draft_timesheet_periods: 0,
            total_accommodation_records: 0,
            assignments: [
                {
                    ...basePreview.assignments[0],
                    has_protected_blockers: true,
                    has_draft_timesheet: false,
                    has_accommodation: false,
                    draft_timesheet: null,
                    accommodation: null,
                    blockers: [
                        {
                            code: 'linked_assignment_exists',
                            message:
                                'Cannot delete CA-2026-000099 because assignment CA-2026-000100 depends on it.',
                        },
                    ],
                    protected_blockers: [
                        {
                            code: 'linked_assignment_exists',
                            message:
                                'Cannot delete CA-2026-000099 because assignment CA-2026-000100 depends on it.',
                        },
                    ],
                    cleanup_blockers: [],
                },
            ],
        };

        const groups = collectProtectedBlockers(protectedPreview);

        assert.equal(groups.length, 1);
        assert.equal(groups[0]?.assignment_no, 'CA-2026-000099');
        assert.equal(
            groups[0]?.blockers[0]?.message,
            'Cannot delete CA-2026-000099 because assignment CA-2026-000100 depends on it.',
        );
        assert.equal(hasLinkedRecordsForCleanup(protectedPreview), false);
    });

    it('builds accommodation summary labels from backend impact data', () => {
        assert.equal(
            accommodationSummaryLabel(basePreview),
            '1 record · Royal Rose · 1 open stay',
        );
    });
});
