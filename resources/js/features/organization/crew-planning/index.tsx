import { DndContext, DragOverlay, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import { useForm } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useCallback, useRef, useState } from 'react';
import {
    store as storeAssignment,
    update as updateAssignment,
    destroy as destroyAssignment,
    confirm as confirmAssignment,
} from '@/actions/App/Http/Controllers/Organization/CrewPlanningAssignmentController';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { cn } from '@/lib/utils';
import { AssignCrewSheet } from './components/assign-crew-sheet';
import { AvailableCrewPool } from './components/available-crew-pool';
import { PlanningGantt } from './components/planning-gantt';
import { PlanningSettingsSheet } from './components/planning-settings-sheet';
import { PlanningToolbar } from './components/planning-toolbar';
import { VesselRankTree } from './components/vessel-rank-tree';
import { dateFromPointerRatio } from './lib/planning-gantt-math';
import type {
    AssignmentFormData,
    CrewDragData,
    GanttBar,
    GanttVesselGroup,
    PlanningFilters,
    PlanningOption,
    PlanningPagePermissions,
    PlanningPoolEmployee,
    PlanningSettings,
    RowDropData,
    TreeVessel,
} from './types';

type AssignDialogState = {
    open: boolean;
    editing: GanttBar | null;
    initialVesselId: string;
    initialRankId: string;
    initialDate: string;
};

const CLOSED_DIALOG: AssignDialogState = {
    open: false,
    editing: null,
    initialVesselId: '',
    initialRankId: '',
    initialDate: '',
};

type Props = {
    rows: GanttVesselGroup[];
    bars: GanttBar[];
    tree: TreeVessel[];
    filters: PlanningFilters;
    today: string;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    departments: PlanningOption[];
    employees: PlanningPoolEmployee[];
    settings: PlanningSettings;
    can: PlanningPagePermissions;
};

export function CrewPlanningContent({
    rows,
    bars,
    tree,
    filters,
    today,
    vessels,
    ranks,
    departments,
    employees,
    settings,
    can,
}: Props): ReactElement {
    const [selectedRowKey, setSelectedRowKey] = useState<string | null>(null);
    const [searchInput, setSearchInput] = useState(filters.search ?? '');
    const [dialogState, setDialogState] = useState<AssignDialogState>(CLOSED_DIALOG);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [confirmBar, setConfirmBar] = useState<GanttBar | null>(null);
    const [isConfirming, setIsConfirming] = useState(false);
    const [draggingEmployee, setDraggingEmployee] = useState<CrewDragData | null>(null);
    const ganttRef = useRef<HTMLDivElement | null>(null);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    );

    const form = useForm<AssignmentFormData>({
        vessel_id: '',
        rank_id: '',
        employee_id: '',
        planned_join_date: '',
        planned_leave_date: '',
        notes: '',
    });

    const openCreate = useCallback(
        (initialVesselId = '', initialRankId = '', initialDate = '', employeeId = ''): void => {
            form.reset();
            form.clearErrors();
            form.setData({
                vessel_id: initialVesselId,
                rank_id: initialRankId,
                employee_id: employeeId,
                planned_join_date: initialDate,
                planned_leave_date: '',
                notes: '',
            });
            setDialogState({ open: true, editing: null, initialVesselId, initialRankId, initialDate });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const openEdit = useCallback((bar: GanttBar): void => {
        form.reset();
        form.clearErrors();
        form.setData({
            vessel_id: bar.row_key.split('|')[0].replace('vessel:', ''),
            rank_id: bar.row_key.split('|')[1].replace('rank:', ''),
            employee_id: bar.employee_id != null ? String(bar.employee_id) : '',
            planned_join_date: bar.joined_date,
            planned_leave_date: bar.disembarked_date ?? '',
            notes: bar.notes ?? '',
        });
        setDialogState({ open: true, editing: bar, initialVesselId: '', initialRankId: '', initialDate: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleDialogOpenChange = (open: boolean): void => {
        if (!open) {
            setDialogState(CLOSED_DIALOG);
        }
    };

    const handleSubmit = (): void => {
        const options = {
            preserveScroll: true,
            onSuccess: () => setDialogState(CLOSED_DIALOG),
        };

        form.transform((data) => ({
            vessel_id: Number(data.vessel_id),
            rank_id: Number(data.rank_id),
            employee_id: data.employee_id !== '' ? Number(data.employee_id) : null,
            planned_join_date: data.planned_join_date,
            planned_leave_date: data.planned_leave_date,
            notes: data.notes || null,
        }));

        if (dialogState.editing) {
            form.put(updateAssignment.url({ assignment: dialogState.editing.id }), options);
        } else {
            form.post(storeAssignment.url(), options);
        }
    };

    const handleDeleteBar = useCallback((bar: GanttBar): void => {
        router.delete(destroyAssignment.url({ assignment: bar.id }), { preserveScroll: true });
    }, []);

    const handleConfirmBar = useCallback((bar: GanttBar): void => {
        setConfirmBar(bar);
    }, []);

    const handleConfirmSubmit = (): void => {
        if (!confirmBar) {
            return;
        }

        setIsConfirming(true);
        router.post(
            confirmAssignment.url({ assignment: confirmBar.id }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setConfirmBar(null);
                    setDialogState(CLOSED_DIALOG);
                },
                onFinish: () => setIsConfirming(false),
            },
        );
    };

    const handleConfirmFromSheet = (): void => {
        if (!dialogState.editing) {
            return;
        }

        handleConfirmBar(dialogState.editing);
    };

    const handleRowSelect = useCallback((rowKey: string): void => {
        setSelectedRowKey((prev) => (prev === rowKey ? null : rowKey));

        const el = ganttRef.current?.querySelector(`[data-row-key="${rowKey}"]`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    const handleRowClick = useCallback(
        (_rowKey: string, vesselId: number, rankId: number, estimatedDate: string): void => {
            openCreate(String(vesselId), String(rankId), estimatedDate);
        },
        [openCreate],
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent): void => {
            setDraggingEmployee(null);

            const { active, over } = event;
            if (!over) {
                return;
            }

            const activeData = active.data.current as CrewDragData | undefined;
            const overData = over.data.current as RowDropData | undefined;

            if (activeData?.type !== 'crew' || overData?.type !== 'row') {
                return;
            }

            const rowEl = document.querySelector(
                `[data-row-key="vessel:${overData.vesselId}|rank:${overData.rankId}"]`,
            ) as HTMLElement | null;
            let estimatedDate = today;
            if (rowEl) {
                const rect = rowEl.getBoundingClientRect();
                const activator = event.activatorEvent as PointerEvent;
                const pointerX = activator.clientX + event.delta.x;
                const ratio = (pointerX - rect.left) / rect.width;
                const rangeFrom = new Date(`${filters.from}T00:00:00`);
                const rangeTo = new Date(`${filters.to}T23:59:59`);
                estimatedDate = dateFromPointerRatio(ratio, rangeFrom, rangeTo);
            }

            openCreate(
                String(overData.vesselId),
                String(activeData.rankId),
                estimatedDate,
                String(activeData.employeeId),
            );
        },
        [openCreate, today, filters.from, filters.to],
    );

    return (
        <DndContext
            sensors={sensors}
            onDragStart={(e) => setDraggingEmployee(e.active.data.current as CrewDragData)}
            onDragEnd={handleDragEnd}
            onDragCancel={() => setDraggingEmployee(null)}
        >
            <Main fixed className="p-0">
                <div className="border-b px-4 pt-5 pb-0">
                    <PageHeader
                        kicker="Crew Operations"
                        title="Crew Planning"
                        description="Visual timeline of crew deployments by vessel and rank."
                    />
                </div>

                <PlanningToolbar
                    filters={filters}
                    vessels={vessels}
                    ranks={ranks}
                    searchInput={searchInput}
                    onSearchChange={setSearchInput}
                    can={can}
                    onAssign={() => openCreate()}
                    onOpenSettings={() => setSettingsOpen(true)}
                />

                <div className="flex min-h-0 flex-1 overflow-hidden">
                    {/* Left sidebar */}
                    <div className="flex w-64 shrink-0 flex-col overflow-hidden border-r">
                        <div className="border-b px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Vessels &amp; Ranks
                        </div>
                        <div className="flex-1 overflow-y-auto">
                            <VesselRankTree
                                tree={tree}
                                search={searchInput}
                                selectedRowKey={selectedRowKey}
                                onRowSelect={handleRowSelect}
                            />
                        </div>

                        {can.create ? (
                            <AvailableCrewPool employees={employees} />
                        ) : null}
                    </div>

                    {/* Gantt */}
                    <div ref={ganttRef} className="flex min-w-0 flex-1 overflow-auto">
                        <PlanningGantt
                            rows={rows}
                            bars={bars}
                            from={filters.from}
                            to={filters.to}
                            today={today}
                            search={searchInput}
                            highlightedRowKey={selectedRowKey}
                            can={can}
                            onRowClick={handleRowClick}
                            onEditBar={openEdit}
                            onDeleteBar={handleDeleteBar}
                            onConfirmBar={handleConfirmBar}
                        />
                    </div>
                </div>

                <AssignCrewSheet
                    open={dialogState.open}
                    onOpenChange={handleDialogOpenChange}
                    form={form}
                    onSubmit={handleSubmit}
                    editing={dialogState.editing}
                    vessels={vessels}
                    ranks={ranks}
                    employees={employees}
                    canConfirm={can.confirm}
                    onConfirm={handleConfirmFromSheet}
                />

                <PlanningSettingsSheet
                    open={settingsOpen}
                    onOpenChange={setSettingsOpen}
                    departments={departments}
                    settings={settings}
                />

                <ConfirmDialog
                    open={confirmBar !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setConfirmBar(null);
                        }
                    }}
                    title="Confirm planned assignment?"
                    desc={
                        confirmBar
                            ? `This will create a deployment record for ${confirmBar.employee_name} on ${confirmBar.vessel_name ?? 'the vessel'} from ${confirmBar.joined_date} to ${confirmBar.disembarked_date ?? confirmBar.joined_date}. The draft bar will be replaced by a deployment bar.`
                            : ''
                    }
                    confirmText={isConfirming ? 'Confirming…' : 'Confirm deployment'}
                    isLoading={isConfirming}
                    handleConfirm={handleConfirmSubmit}
                />
            </Main>

            {/* Drag overlay — shown while dragging crew from the pool */}
            <DragOverlay>
                {draggingEmployee ? (
                    <div
                        className={cn(
                            'flex items-center gap-2 rounded-full border bg-background px-3 py-1.5 text-xs font-medium shadow-lg',
                            'border-blue-300 text-blue-800 dark:border-blue-600 dark:text-blue-300',
                        )}
                    >
                        {draggingEmployee.employeeName}
                        <span className="text-muted-foreground">· {draggingEmployee.rankName}</span>
                    </div>
                ) : null}
            </DragOverlay>
        </DndContext>
    );
}
