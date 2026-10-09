import type {
    CandidateFilters,
    CandidateKanbanColumn,
    CandidateStage,
} from '../types.ts';
import { CANDIDATE_KANBAN_STAGES } from '../types.ts';

export function candidateKanbanFilterKey(
    filters: Pick<
        CandidateFilters,
        | 'search'
        | 'requirement_id'
        | 'requirement_line_id'
        | 'position_id'
        | 'stage'
        | 'outcome'
        | 'per_page'
    >,
    search: string,
): string {
    return JSON.stringify({
        search,
        requirement_id: filters.requirement_id,
        requirement_line_id: filters.requirement_line_id,
        position_id: filters.position_id,
        stage: filters.stage,
        outcome: filters.outcome,
        per_page: filters.per_page,
    });
}

export function mergeKanbanColumn(
    existing: CandidateKanbanColumn | undefined,
    incoming: CandidateKanbanColumn,
    mode: 'replace' | 'append',
): CandidateKanbanColumn {
    if (mode === 'replace' || !existing) {
        return {
            ...incoming,
            data: [...incoming.data],
        };
    }

    const seen = new Set(existing.data.map((row) => row.id));
    const appended = incoming.data.filter((row) => !seen.has(row.id));

    return {
        ...incoming,
        data: [...existing.data, ...appended],
    };
}

export function mergeKanbanBoard(
    previous: Record<string, CandidateKanbanColumn> | null,
    incoming: Record<string, CandidateKanbanColumn> | null,
    appendStage: CandidateStage | null,
): Record<string, CandidateKanbanColumn> | null {
    if (!incoming) {
        return null;
    }

    if (!previous || appendStage === null) {
        const next: Record<string, CandidateKanbanColumn> = {};

        for (const stage of Object.keys(incoming)) {
            next[stage] = mergeKanbanColumn(
                undefined,
                incoming[stage],
                'replace',
            );
        }

        return next;
    }

    const next: Record<string, CandidateKanbanColumn> = { ...previous };

    for (const stage of Object.keys(incoming)) {
        if (stage === appendStage) {
            next[stage] = mergeKanbanColumn(
                previous[stage],
                incoming[stage],
                'append',
            );
        } else if (!(stage in next)) {
            next[stage] = mergeKanbanColumn(
                undefined,
                incoming[stage],
                'replace',
            );
        } else {
            // Preserve other columns; only refresh totals/meta from server when present.
            next[stage] = {
                ...next[stage],
                total: incoming[stage].total,
                last_page: incoming[stage].last_page,
                per_page: incoming[stage].per_page,
            };
        }
    }

    return next;
}

export function visibleKanbanStages(
    stageFilter: string | null | undefined,
): CandidateStage[] {
    if (
        stageFilter &&
        (CANDIDATE_KANBAN_STAGES as string[]).includes(stageFilter)
    ) {
        return [stageFilter as CandidateStage];
    }

    return CANDIDATE_KANBAN_STAGES;
}

/** True when any visible column has accumulated more than the first page. */
export function kanbanHasExpandedPages(
    board: Record<string, CandidateKanbanColumn> | null,
    stages: CandidateStage[],
): boolean {
    if (!board) {
        return false;
    }

    return stages.some((stage) => (board[stage]?.current_page ?? 1) > 1);
}

/**
 * Build through_page_* query params so the server returns pages 1..N for each
 * previously loaded column (authoritative refresh after move/reject/select/etc.).
 */
export function buildKanbanThroughPageParams(
    board: Record<string, CandidateKanbanColumn> | null,
    stages: CandidateStage[],
): Record<string, number> {
    const params: Record<string, number> = {};

    if (!board) {
        return params;
    }

    for (const stage of stages) {
        const through = board[stage]?.current_page ?? 1;

        if (through > 1) {
            params[`through_page_${stage}`] = through;
        }
    }

    return params;
}

/**
 * Decide how to apply an incoming Kanban prop update.
 *
 * - append: Load more for one stage
 * - replace: Filter reset, first paint, page-1 redirect, or completed through refresh
 * - hold_and_refresh: Action redirect while expanded pages are loaded — keep cards
 *   until an authoritative through_page fetch arrives
 */
export function planKanbanBoardSync(options: {
    previous: Record<string, CandidateKanbanColumn> | null;
    incoming: Record<string, CandidateKanbanColumn> | null;
    appendStage: CandidateStage | null;
    isThroughRefresh: boolean;
    stages: CandidateStage[];
}): {
    next: Record<string, CandidateKanbanColumn> | null;
    needsThroughRefresh: boolean;
} {
    const { previous, incoming, appendStage, isThroughRefresh, stages } =
        options;

    if (appendStage !== null) {
        return {
            next: mergeKanbanBoard(previous, incoming, appendStage),
            needsThroughRefresh: false,
        };
    }

    if (isThroughRefresh) {
        return {
            next: mergeKanbanBoard(null, incoming, null),
            needsThroughRefresh: false,
        };
    }

    if (kanbanHasExpandedPages(previous, stages) && previous && incoming) {
        // Keep accumulated cards visible, but refresh totals/meta from the redirect
        // payload until the authoritative through_page response replaces the board.
        const held: Record<string, CandidateKanbanColumn> = { ...previous };

        for (const stage of Object.keys(incoming)) {
            if (!held[stage]) {
                held[stage] = mergeKanbanColumn(
                    undefined,
                    incoming[stage],
                    'replace',
                );
                continue;
            }

            held[stage] = {
                ...held[stage],
                total: incoming[stage].total,
                last_page: incoming[stage].last_page,
                per_page: incoming[stage].per_page,
            };
        }

        return {
            next: held,
            needsThroughRefresh: true,
        };
    }

    return {
        next: mergeKanbanBoard(null, incoming, null),
        needsThroughRefresh: false,
    };
}

/**
 * After an authoritative through refresh + optional later Load more, ensure the
 * moved candidate appears only in its server stage and ids are unique per column.
 */
export function assertKanbanBoardIntegrity(
    board: Record<string, CandidateKanbanColumn> | null,
    movedCandidateId: number,
    expectedStage: CandidateStage,
): {
    foundInExpected: boolean;
    foundElsewhere: boolean;
    duplicateIds: number[];
} {
    if (!board) {
        return {
            foundInExpected: false,
            foundElsewhere: false,
            duplicateIds: [],
        };
    }

    let foundInExpected = false;
    let foundElsewhere = false;
    const duplicateIds: number[] = [];

    for (const [stage, column] of Object.entries(board)) {
        const seen = new Set<number>();

        for (const row of column.data) {
            if (seen.has(row.id)) {
                duplicateIds.push(row.id);
            }

            seen.add(row.id);

            if (row.id === movedCandidateId) {
                if (stage === expectedStage) {
                    foundInExpected = true;
                } else {
                    foundElsewhere = true;
                }
            }
        }
    }

    return { foundInExpected, foundElsewhere, duplicateIds };
}
