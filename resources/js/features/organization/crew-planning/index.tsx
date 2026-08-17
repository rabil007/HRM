import {
    DndContext,
    DragOverlay,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Ship } from 'lucide-react';
import type { ReactElement } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    store as storeAssignment,
    update as updateAssignment,
    destroy as destroyAssignment,
} from '@/actions/App/Http/Controllers/Organization/CrewPlanningAssignmentController';
import { index as planningIndex } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { OnboardByVesselBoard } from '@/features/organization/crew/onboard-by-vessel/onboard-by-vessel-board';
import { onboardSelectionResetKey } from '@/features/organization/crew/onboard-by-vessel/selection-reset-key';
import type { CurrentCrewVesselRow } from '@/features/organization/crew/types';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { AssignCrewSheet } from './components/assign-crew-sheet';
import { CrewPlanningViewSwitcher } from './components/crew-planning-view-switcher';
import { CrewPool } from './components/crew-pool';
import { OnboardPlanningFilters } from './components/onboard-planning-filters';
import { PlanningGantt } from './components/planning-gantt';
import { PlanningLegend } from './components/planning-legend';
import { PlanningToolbar } from './components/planning-toolbar';
import { VesselRankTree } from './components/vessel-rank-tree';
import { findRelievedAssignment } from './lib/find-relieved-assignment';
import { dateFromPointerRatio } from './lib/planning-gantt-math';
import { ZoomProvider } from './lib/zoom-context';
import type {
    AssignmentFormData,
    CrewDragData,
    CrewPlanningView,
    GanttBar,
    GanttVesselGroup,
    PlanningFilters,
    PlanningOption,
    PlanningPagePermissions,
    PlanningPoolEmployee,
    PlanningProjection,
    PlanningProjectionPeriod,
    PlanningReliefPrefill,
    RowDropData,
    TreeVessel,
} from './types';

type AssignDialogState = {
    open: boolean;
    editing: GanttBar | null;
    initialVesselId: string;
    initialRankId: string;
    initialDate: string;
    relievesEmployeeName: string;
};

const CLOSED_DIALOG: AssignDialogState = {
    open: false,
    editing: null,
    initialVesselId: '',
    initialRankId: '',
    initialDate: '',
    relievesEmployeeName: '',
};

type Props = {
    view?: CrewPlanningView;
    rows: GanttVesselGroup[];
    bars: GanttBar[];
    tree: TreeVessel[];
    filters: PlanningFilters;
    today: string;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    employees: PlanningPoolEmployee[];
    can: PlanningPagePermissions;
    projection?: PlanningProjection | null;
    relief_prefill?: PlanningReliefPrefill | null;
    onboard_vessels?: CurrentCrewVesselRow[];
    onboard_pagination?: PaginationMeta;
};

function visitPlanningView(
    view: CrewPlanningView,
    filters: PlanningFilters,
): void {
    const params: Record<string, string> = {};

    if (view === 'onboard-vessels') {
        params.view = 'onboard-vessels';
    }

    if (filters.vessel_id != null) {
        params.vessel_id = String(filters.vessel_id);
    }

    if (filters.rank_id != null) {
        params.rank_id = String(filters.rank_id);
    }

    if (filters.search) {
        params.search = filters.search;
    }

    if (view === 'planning') {
        if (filters.from) {
            params.from = filters.from;
        }

        if (filters.to) {
            params.to = filters.to;
        }
    }

    router.get(planningIndex.url(), params, {
        preserveState: false,
        replace: false,
    });
}

export function CrewPlanningContent({
    view = 'planning',
    rows,
    bars,
    tree,
    filters,
    today,
    vessels,
    ranks,
    employees,
    can,
    projection = null,
    relief_prefill: reliefPrefill = null,
    onboard_vessels: onboardVessels = [],
    onboard_pagination: onboardPagination = {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
        from: null,
        to: null,
    },
}: Props): ReactElement {
    const { current_company_id: currentCompanyId } = usePage().props as {
        current_company_id?: number | null;
    };
    const currentView: CrewPlanningView =
        view === 'onboard-vessels' ? 'onboard-vessels' : 'planning';
    const [selectedRowKey, setSelectedRowKey] = useState<string | null>(null);
    const [searchInput, setSearchInput] = useState(filters.search ?? '');
    const [sidebarOpen, setSidebarOpen] = useState(true);
    const [showCoverage, setShowCoverage] = useState(can.projection);
    const [dialogState, setDialogState] =
        useState<AssignDialogState>(CLOSED_DIALOG);
    const [draggingEmployee, setDraggingEmployee] =
        useState<CrewDragData | null>(null);
    const ganttRef = useRef<HTMLDivElement | null>(null);
    const prefillAppliedRef = useRef(false);

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
        relieves_crew_assignment_id: '',
    });

    const openCreate = useCallback(
        (
            initialVesselId = '',
            initialRankId = '',
            initialDate = '',
            employeeId = '',
            relievesCrewAssignmentId = '',
            relievesEmployeeName = '',
        ): void => {
            form.reset();
            form.clearErrors();
            form.setData({
                vessel_id: initialVesselId,
                rank_id: initialRankId,
                employee_id: employeeId,
                planned_join_date: initialDate,
                planned_leave_date: '',
                notes: '',
                relieves_crew_assignment_id: relievesCrewAssignmentId,
            });
            setDialogState({
                open: true,
                editing: null,
                initialVesselId,
                initialRankId,
                initialDate,
                relievesEmployeeName,
            });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    useEffect(() => {
        if (!reliefPrefill?.open_create || prefillAppliedRef.current) {
            return;
        }

        if (!can.create) {
            return;
        }

        prefillAppliedRef.current = true;
        openCreate(
            reliefPrefill.vessel_id != null
                ? String(reliefPrefill.vessel_id)
                : '',
            reliefPrefill.rank_id != null ? String(reliefPrefill.rank_id) : '',
            reliefPrefill.planned_join_date ?? '',
            '',
            reliefPrefill.relieves_crew_assignment_id != null
                ? String(reliefPrefill.relieves_crew_assignment_id)
                : '',
            reliefPrefill.relieves_employee_name ?? '',
        );
    }, [can.create, openCreate, reliefPrefill]);

    const openCreateForRow = useCallback(
        (
            vesselId: number,
            rankId: number,
            estimatedDate: string,
            employeeId = '',
        ): void => {
            const rowKey = `vessel:${vesselId}|rank:${rankId}`;
            const relieved = findRelievedAssignment(
                bars,
                rowKey,
                estimatedDate,
            );

            openCreate(
                String(vesselId),
                String(rankId),
                relieved?.plannedLeaveDate ?? estimatedDate,
                employeeId,
                relieved ? String(relieved.crewAssignmentId) : '',
                relieved?.employeeName ?? '',
            );
        },
        [bars, openCreate],
    );

    const openEdit = useCallback((bar: GanttBar): void => {
        form.reset();
        form.clearErrors();
        form.setData({
            vessel_id: bar.row_key.split('|')[0].replace('vessel:', ''),
            rank_id: bar.row_key.split('|')[1].replace('rank:', ''),
            employee_id: bar.employee_id != null ? String(bar.employee_id) : '',
            planned_join_date: bar.planned_join_date,
            planned_leave_date: bar.planned_leave_date ?? '',
            notes: bar.notes ?? '',
            relieves_crew_assignment_id:
                bar.relieves_crew_assignment_id != null
                    ? String(bar.relieves_crew_assignment_id)
                    : '',
        });
        setDialogState({
            open: true,
            editing: bar,
            initialVesselId: '',
            initialRankId: '',
            initialDate: '',
            relievesEmployeeName: bar.relieves_employee_name ?? '',
        });
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
            employee_id:
                data.employee_id !== '' ? Number(data.employee_id) : null,
            planned_join_date: data.planned_join_date,
            planned_leave_date: data.planned_leave_date,
            notes: data.notes || null,
            relieves_crew_assignment_id:
                data.relieves_crew_assignment_id !== ''
                    ? Number(data.relieves_crew_assignment_id)
                    : null,
        }));

        if (dialogState.editing) {
            form.put(
                updateAssignment.url({ assignment: dialogState.editing.id }),
                options,
            );
        } else {
            form.post(storeAssignment.url(), options);
        }
    };

    const handleDeleteBar = useCallback((bar: GanttBar): void => {
        router.delete(destroyAssignment.url({ assignment: bar.id }), {
            preserveScroll: true,
        });
    }, []);

    const handleRowSelect = useCallback((rowKey: string): void => {
        setSelectedRowKey((prev) => (prev === rowKey ? null : rowKey));

        const el = ganttRef.current?.querySelector(
            `[data-row-key="${rowKey}"]`,
        );

        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    const handleRowClick = useCallback(
        (
            _rowKey: string,
            vesselId: number,
            rankId: number,
            estimatedDate: string,
        ): void => {
            openCreateForRow(vesselId, rankId, estimatedDate);
        },
        [openCreateForRow],
    );

    const handleGapClick = useCallback(
        (
            vesselId: number,
            rankId: number,
            period: PlanningProjectionPeriod,
        ): void => {
            openCreateForRow(vesselId, rankId, period.from);
        },
        [openCreateForRow],
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

            if (activeData.rankId !== overData.rankId) {
                const rowRank = ranks.find(
                    (rank) => rank.id === overData.rankId,
                );

                toast.error(
                    `${activeData.employeeName} is a ${activeData.rankName} and cannot be assigned to ${rowRank?.name ?? 'this rank'}.`,
                );

                return;
            }

            const timelineEl = document.querySelector(
                `[data-row-key="vessel:${overData.vesselId}|rank:${overData.rankId}"] [data-timeline-container]`,
            ) as HTMLElement | null;
            let estimatedDate = today;

            if (timelineEl) {
                const rect = timelineEl.getBoundingClientRect();
                const activator = event.activatorEvent as PointerEvent;
                const pointerX = activator.clientX + event.delta.x;
                const ratio = (pointerX - rect.left) / rect.width;
                const rangeFrom = new Date(`${filters.from}T00:00:00`);
                const rangeTo = new Date(`${filters.to}T23:59:59`);
                estimatedDate = dateFromPointerRatio(ratio, rangeFrom, rangeTo);
            }

            openCreateForRow(
                overData.vesselId,
                overData.rankId,
                estimatedDate,
                String(activeData.employeeId),
            );
        },
        [openCreateForRow, today, filters.from, filters.to, ranks],
    );

    if (currentView === 'onboard-vessels') {
        const hasActiveQuery =
            Boolean(filters.search?.trim()) ||
            filters.vessel_id != null ||
            filters.rank_id != null;
        const selectionKey = onboardSelectionResetKey({
            companyId: currentCompanyId,
            search: filters.search ?? '',
            filters: {
                vessel_id: filters.vessel_id,
                rank_id: filters.rank_id,
            },
        });

        return (
            <Main>
                <div className="border-b px-4 pt-5 pb-4">
                    <PageHeader
                        kicker="Crew Operations"
                        title="Crew Planning"
                        description="Actual current onboard crew by vessel — not planned Gantt state."
                        right={
                            <CrewPlanningViewSwitcher
                                value={currentView}
                                onChange={(next) =>
                                    visitPlanningView(next, filters)
                                }
                                canViewOnboard={Boolean(can.view_assignments)}
                            />
                        }
                    />
                </div>

                <OnboardPlanningFilters
                    filters={filters}
                    vessels={vessels}
                    ranks={ranks}
                    perPage={onboardPagination.per_page}
                />

                <div className="px-4 py-4">
                    <OnboardByVesselBoard
                        key={selectionKey}
                        vessels={onboardVessels}
                        pagination={onboardPagination}
                        exportQuery={{
                            search: filters.search,
                            vessel_id: filters.vessel_id,
                            rank_id: filters.rank_id,
                        }}
                        onPageChange={(page) => {
                            const params: Record<string, string> = {
                                view: 'onboard-vessels',
                                page: String(page),
                                per_page: String(onboardPagination.per_page),
                            };

                            if (filters.search) {
                                params.search = filters.search;
                            }

                            if (filters.vessel_id != null) {
                                params.vessel_id = String(filters.vessel_id);
                            }

                            if (filters.rank_id != null) {
                                params.rank_id = String(filters.rank_id);
                            }

                            router.get(planningIndex.url(), params, {
                                preserveState: true,
                                preserveScroll: true,
                                replace: true,
                                only: [
                                    'view',
                                    'onboard_vessels',
                                    'onboard_pagination',
                                    'filters',
                                    'can',
                                ],
                            });
                        }}
                        emptyIcon={
                            <Ship className="mx-auto mb-3 size-8 text-muted-foreground/50" />
                        }
                        emptyTitle={
                            hasActiveQuery
                                ? 'No matching onboard vessels'
                                : 'No vessels with onboard crew'
                        }
                        emptyDescription={
                            hasActiveQuery
                                ? 'Try clearing search or filters to widen the roster.'
                                : 'Onboard by Vessel shows current active P4 crew, not planned joins.'
                        }
                        emptyAction={
                            hasActiveQuery ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        visitPlanningView('onboard-vessels', {
                                            ...filters,
                                            vessel_id: null,
                                            rank_id: null,
                                            search: '',
                                        })
                                    }
                                >
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />
                </div>
            </Main>
        );
    }

    return (
        <ZoomProvider>
            <DndContext
                sensors={sensors}
                onDragStart={(e) =>
                    setDraggingEmployee(e.active.data.current as CrewDragData)
                }
                onDragEnd={handleDragEnd}
                onDragCancel={() => setDraggingEmployee(null)}
            >
                <Main fixed className="p-0">
                    <div className="border-b px-4 pt-5 pb-0">
                        <PageHeader
                            kicker="Crew Operations"
                            title="Crew Planning"
                            description="Plan relief crew who will replace deployed crew after they leave the vessel."
                            right={
                                <CrewPlanningViewSwitcher
                                    value={currentView}
                                    onChange={(next) =>
                                        visitPlanningView(next, filters)
                                    }
                                    canViewOnboard={Boolean(
                                        can.view_assignments,
                                    )}
                                />
                            }
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
                        ganttRef={ganttRef}
                        today={today}
                    />

                    <PlanningLegend
                        canProjection={can.projection}
                        showCoverage={showCoverage}
                        onShowCoverageChange={setShowCoverage}
                    />

                    <div className="relative flex min-h-0 flex-1 overflow-hidden">
                        {sidebarOpen ? (
                            <div className="flex w-64 shrink-0 flex-col overflow-hidden border-r bg-muted/10">
                                <div className="border-b border-border/60 bg-background/80 px-3 py-2.5">
                                    <p className="text-[10px] font-bold tracking-widest text-muted-foreground/70 uppercase">
                                        Vessels &amp; Ranks
                                    </p>
                                    <p className="mt-0.5 text-[11px] text-muted-foreground/55">
                                        Select a vessel or rank to focus the
                                        timeline
                                    </p>
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
                                    <CrewPool employees={employees} />
                                ) : null}
                            </div>
                        ) : null}

                        {/* Gantt */}
                        <div
                            ref={ganttRef}
                            className="relative flex min-w-0 flex-1 overflow-auto"
                        >
                            <PlanningGantt
                                rows={rows}
                                bars={bars}
                                from={filters.from}
                                to={filters.to}
                                today={today}
                                search={searchInput}
                                highlightedRowKey={selectedRowKey}
                                can={can}
                                projection={projection}
                                showCoverage={can.projection && showCoverage}
                                onRowClick={handleRowClick}
                                onGapClick={handleGapClick}
                                onEditBar={openEdit}
                                onDeleteBar={handleDeleteBar}
                            />
                        </div>

                        <button
                            type="button"
                            onClick={() => setSidebarOpen((open) => !open)}
                            className={cn(
                                'absolute top-1/2 z-20 flex h-12 w-6 -translate-y-1/2 items-center justify-center border border-border/60 bg-card text-muted-foreground shadow-md transition-colors hover:bg-muted/60 hover:text-foreground',
                                sidebarOpen
                                    ? 'left-64 -translate-x-1/2 rounded-md'
                                    : 'left-0 rounded-r-md border-l-0',
                            )}
                            aria-label={
                                sidebarOpen
                                    ? 'Hide vessels and ranks panel'
                                    : 'Show vessels and ranks panel'
                            }
                            title={
                                sidebarOpen
                                    ? 'Hide vessels & ranks panel'
                                    : 'Show vessels & ranks panel'
                            }
                        >
                            {sidebarOpen ? (
                                <ChevronLeft className="h-4 w-4 shrink-0" />
                            ) : (
                                <ChevronRight className="h-4 w-4 shrink-0" />
                            )}
                        </button>
                    </div>

                    <AssignCrewSheet
                        open={dialogState.open}
                        onOpenChange={handleDialogOpenChange}
                        form={form}
                        onSubmit={handleSubmit}
                        editing={dialogState.editing}
                        relievesEmployeeName={dialogState.relievesEmployeeName}
                        vessels={vessels}
                        ranks={ranks}
                        employees={employees}
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
                            <span className="text-muted-foreground">
                                · {draggingEmployee.rankName}
                            </span>
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>
        </ZoomProvider>
    );
}
