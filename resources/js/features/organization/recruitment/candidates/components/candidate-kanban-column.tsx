import { useDroppable } from '@dnd-kit/core';
import { Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { STAGE_COLORS, STAGE_HINTS } from '../lib/kanban-stage-meta';
import type { CandidateKanbanColumn, CandidateStage } from '../types';
import { CANDIDATE_STAGE_LABELS } from '../types';
import { CandidateKanbanCard } from './candidate-kanban-card';
import type { StageMoveIntent } from './candidate-stage-move-dialog';

export function CandidateKanbanColumnView({
    stage,
    column,
    total,
    onLoadMore,
    onRequestMove,
    compact = false,
    dropHighlight = null,
}: {
    stage: CandidateStage;
    column: CandidateKanbanColumn | undefined;
    total: number;
    onLoadMore: (stage: CandidateStage, page: number) => void;
    onRequestMove: (intent: StageMoveIntent) => void;
    compact?: boolean;
    dropHighlight?: 'valid' | 'invalid' | null;
}) {
    const stageColor = STAGE_COLORS[stage];
    const cards = column?.data ?? [];
    const hint = STAGE_HINTS[stage];
    const loaded = cards.length;
    const { setNodeRef, isOver } = useDroppable({
        id: `stage-${stage}`,
        data: { stage },
    });

    return (
        <section
            ref={setNodeRef}
            className={cn(
                'flex min-h-0 min-w-[220px] flex-col rounded-xl border border-border/60 bg-muted/20 dark:bg-muted/10',
                compact
                    ? 'w-[260px] shrink-0'
                    : 'w-[260px] flex-1 shrink-0 basis-0 lg:min-w-[200px]',
                dropHighlight === 'valid' &&
                    'border-emerald-500/50 ring-2 ring-emerald-500/25',
                dropHighlight === 'invalid' &&
                    'border-rose-500/40 opacity-80 ring-2 ring-rose-500/20',
                isOver && dropHighlight === 'valid' && 'bg-emerald-500/5',
                isOver && dropHighlight === 'invalid' && 'bg-rose-500/5',
            )}
            aria-label={`${CANDIDATE_STAGE_LABELS[stage]} column`}
        >
            <header className="shrink-0 space-y-0.5 border-b border-border/50 px-3 py-2.5">
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
                {dropHighlight === 'invalid' ? (
                    <p className="pl-4 text-[11px] text-rose-700 dark:text-rose-300">
                        Not allowed from this stage
                    </p>
                ) : null}
            </header>

            <div className="min-h-0 flex-1 space-y-2 overflow-x-hidden overflow-y-auto px-2 py-2">
                {cards.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border/50 py-8 text-center">
                        <Users className="h-5 w-5 text-muted-foreground/35" />
                        <p className="text-xs text-muted-foreground">
                            {dropHighlight === 'valid'
                                ? 'Drop here'
                                : 'No candidates'}
                        </p>
                    </div>
                ) : null}

                {cards.map((candidate) => (
                    <CandidateKanbanCard
                        key={candidate.id}
                        candidate={candidate}
                        stage={stage}
                        onRequestMove={onRequestMove}
                    />
                ))}
            </div>

            <footer className="shrink-0 border-t border-border/40 px-2 py-2">
                {column && column.current_page < column.last_page ? (
                    <Button
                        className="w-full text-xs"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            onLoadMore(stage, column.current_page + 1)
                        }
                    >
                        Load {Math.max(0, total - loaded)} more
                    </Button>
                ) : (
                    <p className="px-1 text-center text-[11px] text-muted-foreground tabular-nums">
                        {loaded}/{total}
                    </p>
                )}
            </footer>
        </section>
    );
}
