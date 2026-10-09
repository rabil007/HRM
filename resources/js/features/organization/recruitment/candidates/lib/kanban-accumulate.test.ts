import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CandidateIndexRow, CandidateKanbanColumn } from '../types.ts';
import {
    assertKanbanBoardIntegrity,
    buildKanbanThroughPageParams,
    candidateKanbanFilterKey,
    kanbanHasExpandedPages,
    mergeKanbanBoard,
    mergeKanbanColumn,
    planKanbanBoardSync,
    visibleKanbanStages,
} from './kanban-accumulate.ts';

function row(
    id: number,
    name = `C${id}`,
    stage: CandidateIndexRow['stage'] = 'applied',
): CandidateIndexRow {
    return {
        id,
        name,
        email: null,
        phone: null,
        stage,
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
    total = data.length,
): CandidateKanbanColumn {
    return {
        total,
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

    it('after loading two pages and a candidate action, keeps earlier cards via through refresh', () => {
        const page1 = Array.from({ length: 15 }, (_, index) => row(index + 1));
        const page2 = Array.from({ length: 15 }, (_, index) => row(index + 16));
        const accumulated = {
            applied: column([...page1, ...page2], 2, 3, 40),
            screening: column([row(100, 'S100', 'screening')], 1, 1, 1),
        };

        // Redirect after move returns only the URL page (page 2) — must not wipe page 1.
        const redirectIncoming = {
            applied: column(page2, 2, 3, 39),
            screening: column(
                [row(15, 'C15', 'screening'), row(100, 'S100', 'screening')],
                1,
                1,
                2,
            ),
        };

        const afterRedirect = planKanbanBoardSync({
            previous: accumulated,
            incoming: redirectIncoming,
            appendStage: null,
            isThroughRefresh: false,
            stages: ['applied', 'screening', 'interview', 'rejected'],
        });

        assert.equal(afterRedirect.needsThroughRefresh, true);
        assert.deepEqual(
            afterRedirect.next?.applied.data.map((item) => item.id),
            [...page1, ...page2].map((item) => item.id),
        );
        assert.equal(afterRedirect.next?.applied.total, 39);
        assert.equal(afterRedirect.next?.screening.total, 2);
        assert.deepEqual(
            buildKanbanThroughPageParams(accumulated, [
                'applied',
                'screening',
                'interview',
                'rejected',
            ]),
            { through_page_applied: 2 },
        );

        // Authoritative through_page response: pages 1..2 without moved card 15.
        const throughPayload = {
            applied: column(
                [...page1.filter((item) => item.id !== 15), ...page2],
                2,
                3,
                39,
            ),
            screening: column(
                [row(15, 'C15', 'screening'), row(100, 'S100', 'screening')],
                1,
                1,
                2,
            ),
        };

        const afterRefresh = planKanbanBoardSync({
            previous: afterRedirect.next,
            incoming: throughPayload,
            appendStage: null,
            isThroughRefresh: true,
            stages: ['applied', 'screening', 'interview', 'rejected'],
        });

        assert.equal(afterRefresh.needsThroughRefresh, false);
        assert.equal(afterRefresh.next?.applied.data.length, 29);
        assert.equal(
            afterRefresh.next?.applied.data.some((item) => item.id === 1),
            true,
        );

        const integrity = assertKanbanBoardIntegrity(
            afterRefresh.next,
            15,
            'screening',
        );
        assert.equal(integrity.foundInExpected, true);
        assert.equal(integrity.foundElsewhere, false);
        assert.deepEqual(integrity.duplicateIds, []);

        // Subsequent Load more appends page 3 without duplicating earlier ids.
        const page3 = [row(31), row(32)];
        const afterLoadMore = planKanbanBoardSync({
            previous: afterRefresh.next,
            incoming: {
                applied: column(page3, 3, 3, 39),
                screening: throughPayload.screening,
            },
            appendStage: 'applied',
            isThroughRefresh: false,
            stages: ['applied', 'screening', 'interview', 'rejected'],
        });

        const appliedIds = afterLoadMore.next?.applied.data.map(
            (item) => item.id,
        );
        assert.deepEqual(appliedIds?.slice(-2), [31, 32]);
        assert.equal(new Set(appliedIds).size, appliedIds?.length);
        assert.equal(appliedIds?.includes(1), true);
        assert.equal(appliedIds?.includes(15), false);
    });

    it('replaces immediately when only page 1 is loaded after an action redirect', () => {
        const previous = {
            applied: column([row(1), row(2)], 1, 1, 2),
        };
        const incoming = {
            applied: column([row(2)], 1, 1, 1),
        };

        const plan = planKanbanBoardSync({
            previous,
            incoming,
            appendStage: null,
            isThroughRefresh: false,
            stages: ['applied'],
        });

        assert.equal(plan.needsThroughRefresh, false);
        assert.deepEqual(
            plan.next?.applied.data.map((item) => item.id),
            [2],
        );
        assert.equal(kanbanHasExpandedPages(previous, ['applied']), false);
    });
});
