import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    closestCorners,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
} from '@dnd-kit/core';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    destinationForStage,
    isDropAllowed,
} from '../lib/candidate-move-destinations';
import type {
    CandidateIndexRow,
    CandidateKanbanColumn,
    CandidateStage,
} from '../types';
import { CandidateKanbanCard } from './candidate-kanban-card';
import { CandidateKanbanColumnView } from './candidate-kanban-column';
import type { StageMoveIntent } from './candidate-stage-move-dialog';

type DragCandidateData = {
    candidate: CandidateIndexRow;
    stage: CandidateStage;
};

function parseStageDropId(id: string | number): CandidateStage | null {
    const value = String(id);

    if (!value.startsWith('stage-')) {
        return null;
    }

    return value.slice('stage-'.length) as CandidateStage;
}

export function CandidateKanbanBoard({
    stages,
    rejectedStage,
    accumulatedKanban,
    stageTotals,
    onLoadMore,
    onRequestMove,
    onRequestSelect,
    onRequestPrepareOffer,
    onRequestReadiness,
}: {
    stages: CandidateStage[];
    rejectedStage: CandidateStage | null;
    accumulatedKanban: Record<string, CandidateKanbanColumn>;
    stageTotals: Record<string, number>;
    onLoadMore: (stage: CandidateStage, page: number) => void;
    onRequestMove: (intent: StageMoveIntent) => void;
    onRequestSelect?: (candidate: CandidateIndexRow) => void;
    onRequestPrepareOffer?: (candidate: CandidateIndexRow) => void;
    onRequestReadiness?: (candidate: CandidateIndexRow) => void;
}) {
    const [rejectedOpen, setRejectedOpen] = useState(false);
    const [activeDrag, setActiveDrag] = useState<DragCandidateData | null>(
        null,
    );
    const [overStage, setOverStage] = useState<CandidateStage | null>(null);
    const rejectedTotal = rejectedStage
        ? (stageTotals[rejectedStage] ??
          accumulatedKanban[rejectedStage]?.total ??
          0)
        : 0;
    const focusingRejected = stages.length === 1 && stages[0] === 'rejected';

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
        useSensor(KeyboardSensor),
    );

    const dropHighlight = (
        stage: CandidateStage,
    ): 'valid' | 'invalid' | null => {
        if (!activeDrag || overStage !== stage) {
            return null;
        }

        if (activeDrag.stage === stage) {
            return null;
        }

        return isDropAllowed(activeDrag.candidate, stage) ? 'valid' : 'invalid';
    };

    const handleDragStart = (event: DragStartEvent) => {
        const data = event.active.data.current as DragCandidateData | undefined;

        if (!data?.candidate) {
            return;
        }

        setActiveDrag(data);
        setOverStage(data.stage);
    };

    const handleDragOver = (event: DragOverEvent) => {
        const stage =
            parseStageDropId(event.over?.id ?? '') ??
            (event.over?.data.current as { stage?: CandidateStage } | undefined)
                ?.stage ??
            null;

        setOverStage(stage);
    };

    const handleDragCancel = () => {
        setActiveDrag(null);
        setOverStage(null);
    };

    const handleDragEnd = (event: DragEndEvent) => {
        const drag = activeDrag;
        setActiveDrag(null);
        setOverStage(null);

        if (!drag) {
            return;
        }

        const destinationStage =
            parseStageDropId(event.over?.id ?? '') ??
            (event.over?.data.current as { stage?: CandidateStage } | undefined)
                ?.stage ??
            null;

        if (!destinationStage || destinationStage === drag.stage) {
            return;
        }

        const destination = destinationForStage(
            drag.candidate,
            destinationStage,
        );

        if (
            !destination ||
            destination.kind === 'blocked' ||
            destination.kind === 'select_required'
        ) {
            return;
        }

        onRequestMove({
            candidate: drag.candidate,
            destination,
        });
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            onDragStart={handleDragStart}
            onDragOver={handleDragOver}
            onDragEnd={handleDragEnd}
            onDragCancel={handleDragCancel}
        >
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
                                onRequestMove={onRequestMove}
                                onRequestSelect={onRequestSelect}
                                onRequestPrepareOffer={onRequestPrepareOffer}
                                onRequestReadiness={onRequestReadiness}
                                compact={
                                    focusingRejected || stages.length === 1
                                }
                                dropHighlight={dropHighlight(stage)}
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
                                onRequestMove={onRequestMove}
                                onRequestSelect={onRequestSelect}
                                onRequestPrepareOffer={onRequestPrepareOffer}
                                onRequestReadiness={onRequestReadiness}
                                compact
                                dropHighlight={dropHighlight(rejectedStage)}
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

            <DragOverlay dropAnimation={null}>
                {activeDrag ? (
                    <div className="w-[244px] cursor-grabbing">
                        <CandidateKanbanCard
                            candidate={activeDrag.candidate}
                            stage={activeDrag.stage}
                            isDragOverlay
                        />
                    </div>
                ) : null}
            </DragOverlay>
        </DndContext>
    );
}
