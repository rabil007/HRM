import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewTimesheet, MovementMasterOptions } from '../types.ts';
import {
    buildAssignmentSummaryFields,
    createEmptyMovementPeriodDraft,
    draftFromExistingSegment,
    formatAssignmentFieldValue,
    inclusiveMovementDays,
    isAssignmentEditorOpen,
    resolveDefaultAssignment,
    segmentDraftsFromTimesheet,
    toggleAssignmentEditor,
} from './crew-movement-period-drafts.ts';

const masterOptions: MovementMasterOptions = {
    vessels: [
        { id: 1, name: 'MV Aurora' },
        { id: 2, name: 'MV Borealis' },
    ],
    clients: [{ id: 10, name: 'North Yard' }],
    ranks: [{ id: 100, name: 'Able Seaman' }],
};

describe('crew movement period drafts', () => {
    it('loads existing segment assignment values into the edit form drafts', () => {
        const timesheet = {
            segments: [
                {
                    id: 5,
                    sequence: 1,
                    pay_category: 'onsite',
                    pay_category_label: 'Onsite',
                    from_date: '2026-07-01',
                    to_date: '2026-07-10',
                    days: '10',
                    source: 'manual',
                    source_label: 'Manual',
                    crew_assignment_id: null,
                    assignment_no: null,
                    crew_assignment_phase_id: null,
                    vessel_id: 1,
                    vessel_name: 'MV Aurora',
                    client_id: 10,
                    client_name: 'North Yard',
                    rank_id: 100,
                    rank_name: 'Able Seaman',
                    remarks: 'Keep me',
                },
            ],
        } as CrewTimesheet;

        const drafts = segmentDraftsFromTimesheet(timesheet);

        assert.equal(drafts.length, 1);
        assert.equal(drafts[0]?.vessel_id, 1);
        assert.equal(drafts[0]?.client_id, 10);
        assert.equal(drafts[0]?.rank_id, 100);
        assert.equal(drafts[0]?.remarks, 'Keep me');
        assert.equal(drafts[0]?.from_date, '2026-07-01');
    });

    it('keeps assignment editors collapsed by default', () => {
        const openKeys = new Set<string>();

        assert.equal(isAssignmentEditorOpen(openKeys, 'existing-1-0'), false);
    });

    it('reveals assignment selectors when Change Assignment is toggled on', () => {
        const openKeys = toggleAssignmentEditor(
            new Set(),
            'existing-1-0',
            true,
        );

        assert.equal(isAssignmentEditorOpen(openKeys, 'existing-1-0'), true);
        assert.equal(
            isAssignmentEditorOpen(
                toggleAssignmentEditor(openKeys, 'existing-1-0', false),
                'existing-1-0',
            ),
            false,
        );
    });

    it('preserves assignment values when untouched', () => {
        const segment = draftFromExistingSegment(
            {
                id: 9,
                sequence: 1,
                pay_category: 'onsite',
                pay_category_label: 'Onsite',
                from_date: '2026-07-01',
                to_date: '2026-07-05',
                days: '5',
                source: 'manual',
                source_label: 'Manual',
                crew_assignment_id: null,
                assignment_no: null,
                crew_assignment_phase_id: null,
                vessel_id: 2,
                vessel_name: 'MV Borealis',
                client_id: 10,
                client_name: 'North Yard',
                rank_id: 100,
                rank_name: 'Able Seaman',
                remarks: null,
            },
            0,
        );

        const payload = {
            pay_category: segment.pay_category,
            vessel_id: segment.vessel_id,
            client_id: segment.client_id,
            rank_id: segment.rank_id,
            from_date: '2026-07-02',
            to_date: segment.to_date,
            remarks: segment.remarks || null,
        };

        assert.equal(payload.vessel_id, 2);
        assert.equal(payload.client_id, 10);
        assert.equal(payload.rank_id, 100);
        assert.equal(payload.from_date, '2026-07-02');
    });

    it('allows assignment values to be changed or cleared', () => {
        let segment = createEmptyMovementPeriodDraft('new-1', {
            vessel_id: 1,
            client_id: 10,
            rank_id: 100,
        });

        segment = { ...segment, vessel_id: 2, client_id: null, rank_id: 100 };

        assert.equal(segment.vessel_id, 2);
        assert.equal(segment.client_id, null);
        assert.equal(segment.rank_id, 100);

        const summary = buildAssignmentSummaryFields(segment, masterOptions);
        assert.equal(summary[0]?.value, 'MV Borealis');
        assert.equal(summary[1]?.value, 'Not assigned');
        assert.equal(summary[1]?.assigned, false);
        assert.equal(summary[2]?.value, 'Able Seaman');
    });

    it('defaults new rows from the latest resolved segment assignment', () => {
        const drafts = [
            createEmptyMovementPeriodDraft('a', {
                vessel_id: 1,
                client_id: 10,
                rank_id: 100,
            }),
        ];
        const defaults = resolveDefaultAssignment(drafts, null);
        const next = createEmptyMovementPeriodDraft('b', defaults);

        assert.equal(next.vessel_id, 1);
        assert.equal(next.client_id, 10);
        assert.equal(next.rank_id, 100);
    });

    it('shows Not assigned for missing assignment labels', () => {
        assert.equal(formatAssignmentFieldValue(null), 'Not assigned');
        assert.equal(formatAssignmentFieldValue(''), 'Not assigned');
        assert.equal(formatAssignmentFieldValue('MV Aurora'), 'MV Aurora');
    });

    it('calculates inclusive movement days for validation display', () => {
        assert.equal(inclusiveMovementDays('2026-07-01', '2026-07-04'), 4);
        assert.equal(inclusiveMovementDays('2026-07-04', '2026-07-01'), null);
        assert.equal(inclusiveMovementDays('', '2026-07-01'), null);
    });
});
