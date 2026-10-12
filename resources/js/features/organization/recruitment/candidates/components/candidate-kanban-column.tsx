import { Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { STAGE_COLORS, STAGE_HINTS } from '../lib/kanban-stage-meta';
import type { CandidateKanbanColumn, CandidateStage } from '../types';
import { CANDIDATE_STAGE_LABELS } from '../types';
import { CandidateKanbanCard } from './candidate-kanban-card';

export function CandidateKanbanColumnView({
    stage,
    column,
    total,
    onLoadMore,
}: {
    stage: CandidateStage;
    column: CandidateKanbanColumn | undefined;
    total: number;
    onLoadMore: (stage: CandidateStage, page: number) => void;
}) {
    const stageColor = STAGE_COLORS[stage];
    const cards = column?.data ?? [];
    const hint = STAGE_HINTS[stage];

    return (
        <div className="flex w-[280px] shrink-0 flex-col rounded-xl border border-border/60 bg-muted/20 dark:bg-muted/10">
            <div className="space-y-1 border-b border-border/50 px-3 py-3">
                <div className="flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2">
                        <span
                            className={cn(
                                'h-2 w-2 shrink-0 rounded-full',
                                stageColor.accent,
                            )}
                            aria-hidden
                        />
                        <h3 className="truncate text-sm font-semibold tracking-tight">
                            {CANDIDATE_STAGE_LABELS[stage]}
                        </h3>
                    </div>
                    <span
                        className={cn(
                            'inline-flex h-5 min-w-5 items-center justify-center rounded-md px-1.5 text-xs font-semibold tabular-nums',
                            stageColor.badge,
                        )}
                    >
                        {total}
                    </span>
                </div>
                {hint ? (
                    <p className="truncate pl-4 text-[11px] text-muted-foreground">
                        {hint}
                    </p>
                ) : null}
            </div>

            <div
                className="flex-1 space-y-2 overflow-y-auto px-2 py-2"
                style={{ maxHeight: '70vh' }}
            >
                {cards.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border/50 py-10 text-center">
                        <Users className="h-5 w-5 text-muted-foreground/35" />
                        <p className="text-xs text-muted-foreground">
                            No candidates
                        </p>
                    </div>
                ) : null}

                {cards.map((candidate) => (
                    <CandidateKanbanCard
                        key={candidate.id}
                        candidate={candidate}
                        stage={stage}
                    />
                ))}

                {column && column.current_page < column.last_page ? (
                    <Button
                        className="mt-1 w-full text-xs"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            onLoadMore(stage, column.current_page + 1)
                        }
                    >
                        Load {column.total - cards.length} more…
                    </Button>
                ) : null}
            </div>
        </div>
    );
}
