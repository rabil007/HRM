import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow, CandidateKanbanColumn } from '../types.ts';
import {
    candidateKanbanFilterKey,
    mergeKanbanBoard,
    mergeKanbanColumn,
    visibleKanbanStages,
} from './kanban-accumulate.ts';

function row(id: number, name = `C${id}`): CandidateIndexRow {
    return {
        id,
        name,
        email: null,
        phone: null,
        stage: 'applied',
        stage_label: 'Applied',
        stage_badge: 'secondary',
        interview_outcome: null,
        interview_outcome_label: null,
        interview_outcome_badge: null,
        requirement_id: 1,
        requirement_number: 'REQ-1',
        requirement_line_id: 1,
        position_title: 'Officer',
        source: null,
        source_label: null,
        nationality: null,
        has_cv: false,
        interview_scheduled_at: null,
        lock_version: 0,
        created_at: null,
        parents_valid: true,
        can_update: true,
        can_move: true,
        can_move_forward: true,
        can_reject: true,
        can_select: false,
        can_undo_selected: false,
        can_reopen: false,
        can_download_cv: false,
    };
}

function column(
    data: CandidateIndexRow[],
    page = 1,
    lastPage = 2,
): CandidateKanbanColumn {
    return {
        total: data.length,
        data,
        current_page: page,
        last_page: lastPage,
        per_page: 15,
        from: 1,
        to: data.length,
    };
}

describe('kanban accumulate helpers', () => {
    it('appends and deduplicates cards for one column', () => {
        const existing = column([row(1), row(2)], 1, 2);
        const incoming = column([row(2), row(3)], 2, 2);
        const merged = mergeKanbanColumn(existing, incoming, 'append');

        assert.deepEqual(
            merged.data.map((item) => item.id),
            [1, 2, 3],
        );
        assert.equal(merged.current_page, 2);
    });

    it('preserves other columns when appending one stage', () => {
        const previous = {
            applied: column([row(1)], 1, 2),
            screening: column([row(10)], 1, 1),
        };
        const incoming = {
            applied: column([row(2)], 2, 2),
            screening: column([row(99)], 1, 1),
        };

        const merged = mergeKanbanBoard(previous, incoming, 'applied');

        assert.deepEqual(
            merged?.applied.data.map((item) => item.id),
            [1, 2],
        );
        assert.deepEqual(
            merged?.screening.data.map((item) => item.id),
            [10],
        );
    });

    it('resets board when filters change (replace mode)', () => {
        const previous = {
            applied: column([row(1), row(2)], 2, 2),
        };
        const incoming = {
            applied: column([row(5)], 1, 1),
        };

        const merged = mergeKanbanBoard(previous, incoming, null);

        assert.deepEqual(
            merged?.applied.data.map((item) => item.id),
            [5],
        );
        assert.equal(
            candidateKanbanFilterKey(
                {
                    search: 'a',
                    requirement_id: 1,
                    requirement_line_id: null,
                    position_id: null,
                    stage: null,
                    outcome: null,
                    per_page: 15,
                },
                'a',
            ) !==
                candidateKanbanFilterKey(
                    {
                        search: 'b',
                        requirement_id: 1,
                        requirement_line_id: null,
                        position_id: null,
                        stage: null,
                        outcome: null,
                        per_page: 15,
                    },
                    'b',
                ),
            true,
        );
    });

    it('limits visible kanban stages when a stage filter is set', () => {
        assert.deepEqual(visibleKanbanStages('screening'), ['screening']);
        assert.deepEqual(visibleKanbanStages(null), [
            'applied',
            'screening',
            'interview',
            'rejected',
        ]);
    });
});
