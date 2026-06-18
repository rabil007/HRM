import {
    DndContext,
    DragOverlay,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent, DragStartEvent } from '@dnd-kit/core';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { BoardColumn } from '@/features/organization/crew-deployments/board-column';
import { DeploymentCard } from '@/features/organization/crew-deployments/deployment-card';
import { StatusTransitionDialog } from '@/features/organization/crew-deployments/status-transition-dialog';
import type {
    DeploymentItem,
    DeploymentPagePermissions,
    DeploymentSummary,
} from '@/features/organization/crew-deployments/types';

type Option = { id: number; name: string };

type Props = {
    deployments: DeploymentItem[];
    summary: DeploymentSummary;
    can: DeploymentPagePermissions;
    vessels: Option[];
    onEdit: (deployment: DeploymentItem) => void;
    onDelete: (deployment: DeploymentItem) => void;
    backQuery?: Record<string, string>;
};

const COLUMNS = [
    { status: 'arrived', label: 'Arrived' },
    { status: 'join_standby', label: 'Join standby' },
    { status: 'on_vessel', label: 'On vessel' },
    { status: 'disembarked', label: 'Disembarked' },
    { status: 'leave_standby', label: 'Leave standby' },
    { status: 'travel', label: 'Travel' },
    { status: 'in_home', label: 'In home' },
] as const;

export function CrewDeploymentsBoard({
    deployments,
    summary,
    can,
    vessels,
    onEdit,
    onDelete,
    backQuery,
}: Props): ReactElement {
    const [draggingDeployment, setDraggingDeployment] = useState<DeploymentItem | null>(null);
    const [pendingTransition, setPendingTransition] = useState<{
        deployment: DeploymentItem;
        targetStatus: string;
    } | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
    );

    const { unknownDeployments, deploymentsByStatus } = useMemo(() => {
        const unknown: DeploymentItem[] = [];
        const grouped: Record<string, DeploymentItem[]> = {
            arrived: [],
            join_standby: [],
            on_vessel: [],
            disembarked: [],
            leave_standby: [],
            travel: [],
            in_home: [],
        };

        deployments.forEach((dep) => {
            if (dep.status === 'unknown') {
                unknown.push(dep);
            } else if (grouped[dep.status]) {
                grouped[dep.status].push(dep);
            }
        });

        return { unknownDeployments: unknown, deploymentsByStatus: grouped };
    }, [deployments]);

    const handleDragStart = ({ active }: DragStartEvent): void => {
        const dep = active.data.current?.deployment as DeploymentItem | undefined;

        if (dep) {
            setDraggingDeployment(dep);
        }
    };

    const handleDragEnd = ({ active, over }: DragEndEvent): void => {
        setDraggingDeployment(null);

        if (!over) {
            return;
        }

        const dep = active.data.current?.deployment as DeploymentItem | undefined;
        const targetStatus = over.id as string;

        if (!dep || dep.status === targetStatus) {
            return;
        }

        setPendingTransition({ deployment: dep, targetStatus });
    };

    return (
        <>
            <DndContext
                sensors={sensors}
                onDragStart={handleDragStart}
                onDragEnd={handleDragEnd}
            >
                {/* ── Needs-attention tray ─────────────────────────────── */}
                {unknownDeployments.length > 0 ? (
                    <div className="mb-5">
                        <div className="mb-2 flex items-center gap-2">
                            <span className="h-2 w-2 rounded-full bg-red-500" />
                            <span className="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400">
                                Needs attention
                            </span>
                            <span className="rounded-full border border-red-500/30 bg-red-500/10 px-1.5 py-0 text-[10px] font-bold tabular-nums text-red-600 dark:text-red-400">
                                {unknownDeployments.length}
                            </span>
                            <span className="text-[11px] text-muted-foreground/60">
                                — drag to the correct stage to resolve
                            </span>
                        </div>
                        <div className="flex gap-3 overflow-x-auto pb-1">
                            {unknownDeployments.map((dep) => (
                                <div key={dep.id} className="w-[300px] shrink-0">
                                    <DeploymentCard
                                        deployment={dep}
                                        can={can}
                                        onEdit={onEdit}
                                        onDelete={onDelete}
                                        backQuery={backQuery}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {/* ── Kanban columns ───────────────────────────────────── */}
                <div className="relative -mx-6 px-6">
                    <div className="overflow-x-auto pb-6">
                        <div
                            className="flex gap-3 py-1"
                            style={{ minHeight: 'calc(100vh - 400px)' }}
                        >
                            {COLUMNS.map((column) => (
                                <BoardColumn
                                    key={column.status}
                                    status={column.status}
                                    label={column.label}
                                    count={summary[column.status] ?? 0}
                                    deployments={deploymentsByStatus[column.status] ?? []}
                                    can={can}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                    backQuery={backQuery}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <DragOverlay dropAnimation={null}>
                    {draggingDeployment ? (
                        <div className="w-[300px]">
                            <DeploymentCard
                                deployment={draggingDeployment}
                                can={can}
                                onEdit={onEdit}
                                onDelete={onDelete}
                                backQuery={backQuery}
                                isOverlay
                            />
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>

            <StatusTransitionDialog
                open={pendingTransition !== null}
                deployment={pendingTransition?.deployment ?? null}
                targetStatus={pendingTransition?.targetStatus ?? null}
                vessels={vessels}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingTransition(null);
                    }
                }}
            />
        </>
    );
}
