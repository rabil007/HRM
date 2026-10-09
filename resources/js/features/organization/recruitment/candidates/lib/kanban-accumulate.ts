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
