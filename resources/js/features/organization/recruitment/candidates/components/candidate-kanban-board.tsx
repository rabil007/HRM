import { TooltipProvider } from '@/components/ui/tooltip';
import type { CandidateKanbanColumn, CandidateStage } from '../types';
import { CandidateKanbanColumnView } from './candidate-kanban-column';

export function CandidateKanbanBoard({
    stages,
    accumulatedKanban,
    stageTotals,
    onLoadMore,
}: {
    stages: CandidateStage[];
    accumulatedKanban: Record<string, CandidateKanbanColumn>;
    stageTotals: Record<string, number>;
    onLoadMore: (stage: CandidateStage, page: number) => void;
}) {
    return (
        <TooltipProvider>
            <div className="-mx-1 overflow-x-auto pb-2">
                <div className="flex min-w-min gap-3 px-1">
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
                            />
                        );
                    })}
                </div>
            </div>
        </TooltipProvider>
    );
}
