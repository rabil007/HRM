import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CandidateKanbanColumn, CandidateStage } from '../types';
import { CandidateKanbanColumnView } from './candidate-kanban-column';

export function CandidateKanbanBoard({
    stages,
    rejectedStage,
    accumulatedKanban,
    stageTotals,
    onLoadMore,
}: {
    stages: CandidateStage[];
    rejectedStage: CandidateStage | null;
    accumulatedKanban: Record<string, CandidateKanbanColumn>;
    stageTotals: Record<string, number>;
    onLoadMore: (stage: CandidateStage, page: number) => void;
}) {
    const [rejectedOpen, setRejectedOpen] = useState(false);
    const rejectedTotal = rejectedStage
        ? (stageTotals[rejectedStage] ??
          accumulatedKanban[rejectedStage]?.total ??
          0)
        : 0;
    const focusingRejected = stages.length === 1 && stages[0] === 'rejected';

    return (
        <div className="flex h-full min-h-0 w-full gap-2 overflow-hidden">
            <div
                className={cn(
                    'flex min-h-0 min-w-0 flex-1 gap-3 overflow-x-auto overflow-y-hidden pb-1',
                    focusingRejected && 'justify-start',
                )}
            >
                {stages.map((stage) => {
                    const column = accumulatedKanban[stage];
                    const total = stageTotals[stage] ?? column?.total ?? 0;

                    return (
                        <CandidateKanbanColumnView
                            key={stage}
                            stage={stage}
                            column={column}
                            total={total}
                            onLoadMore={onLoadMore}
                            compact={focusingRejected || stages.length === 1}
                        />
                    );
                })}
            </div>

            {rejectedStage && !focusingRejected ? (
                rejectedOpen ? (
                    <div className="flex h-full min-h-0 shrink-0 gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="mt-1 h-8 w-8 shrink-0"
                            onClick={() => setRejectedOpen(false)}
                            aria-label="Collapse rejected lane"
                            title="Collapse rejected"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                        <CandidateKanbanColumnView
                            stage={rejectedStage}
                            column={accumulatedKanban[rejectedStage]}
                            total={rejectedTotal}
                            onLoadMore={onLoadMore}
                            compact
                        />
                    </div>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="h-full w-10 shrink-0 flex-col gap-2 rounded-xl border-dashed px-1 py-3 text-muted-foreground hover:text-foreground"
                        onClick={() => setRejectedOpen(true)}
                        aria-label={`Show rejected candidates (${rejectedTotal})`}
                        title="Show rejected"
                    >
                        <ChevronLeft className="h-4 w-4" />
                        <span
                            className="text-[10px] font-semibold tracking-wide uppercase"
                            style={{ writingMode: 'vertical-rl' }}
                        >
                            Rejected
                        </span>
                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-semibold tabular-nums">
                            {rejectedTotal}
                        </span>
                    </Button>
                )
            ) : null}
        </div>
    );
}
