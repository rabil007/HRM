import { router, useForm, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { VesselTransferRecommendationDialog } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { CrewAssignmentBulkReadinessPanel } from '@/features/organization/crew/components/crew-assignment-bulk-readiness-panel';
import { CrewAssignmentCommonFields } from '@/features/organization/crew/components/crew-assignment-common-fields';
import type { ConflictDialogData } from '@/features/organization/crew/components/crew-assignment-conflict-dialog';
import { CrewAssignmentConflictDialog } from '@/features/organization/crew/components/crew-assignment-conflict-dialog';
import { CrewAssignmentReadinessPanel } from '@/features/organization/crew/components/crew-assignment-readiness-panel';
import type { CrewMemberRowState } from '@/features/organization/crew/components/crew-members-section';
import { CrewMembersSection } from '@/features/organization/crew/components/crew-members-section';
import { PlanningStartActiveAssignmentConflict } from '@/features/organization/crew/components/planning-start-active-assignment-conflict';
import { PlanningStartAuthoritativeFields } from '@/features/organization/crew/components/planning-start-authoritative-fields';
import {
    clearBulkCreateSession,
    loadBulkCreateSession,
    saveBulkCreateSession,
} from '@/features/organization/crew/lib/bulk-create-session';
import type {
    BulkPreviewFilter,
    BulkSidebarMode,
} from '@/features/organization/crew/lib/bulk-readiness-preview';
import {
    bulkStartHelperText,
    removeBlockedBulkRows,
    resolveNextPreviewRowKey,
} from '@/features/organization/crew/lib/bulk-readiness-preview';
import {
    bulkFieldError,
    canSubmitBulkBatch,
    summarizeBulkRows,
} from '@/features/organization/crew/lib/bulk-row-status';
import {
    clearCreateFormDateErrors,
    dependentCreateDateErrorKeys,
    resolveArrivalDateDisplayError,
} from '@/features/organization/crew/lib/crew-assignment-create-date-validation';
import {
    bulkStartButtonLabel,
    isBulkCreateMode,
    resolveCreateEffectiveEmployeeId,
    resolveCreateFooterActions,
    resolveCreateSubmitRoute,
} from '@/features/organization/crew/lib/crew-assignment-create-mode';
import {
    canUseManualTransferRecommendation,
    hasPlanningStartActiveAssignmentConflict,
} from '@/features/organization/crew/lib/vessel-transfer-recommendation';
import type {
    BulkAddCrewFormData,
    BulkAddCrewRow,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
    CrewPlanningBackQuery,
    CrewPlanningStartContext,
} from '@/features/organization/crew/types';
import { dashboard } from '@/routes';
import {
    bulkStore,
    index as crewAssignmentsIndex,
    store as storeAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

let nextRowKey = 1;

function newCrewRow(): CrewMemberRowState {
    nextRowKey += 1;

    return {
        key: `crew-row-${nextRowKey}`,
        employee_id: null,
        rank_id: null,
    };
}

function createInitialRows(
    initialRowCount: number,
    planningContext?: CrewPlanningStartContext | null,
    prefill?: {
        employee_id?: number | null;
        rank_id?: number | null;
    } | null,
): {
    rows: CrewMemberRowState[];
    keys: string[];
} {
    if (planningContext) {
        const row: CrewMemberRowState = {
            key: 'crew-row-planning',
            employee_id: planningContext.employee_id,
            rank_id: planningContext.rank_id,
            planned_arrival_at: planningContext.planned_arrival_at ?? null,
        };

        return {
            rows: [row],
            keys: [row.key],
        };
    }

    const count = Math.max(1, initialRowCount);
    const rows: CrewMemberRowState[] = [];

    for (let index = 0; index < count; index += 1) {
        const row = newCrewRow();

        if (index === 0 && prefill) {
            if (prefill.employee_id) {
                row.employee_id = prefill.employee_id;
            }

            if (prefill.rank_id) {
                row.rank_id = prefill.rank_id;
            }
        }

        rows.push(row);
    }

    return {
        rows,
        keys: rows.map((row) => row.key),
    };
}

function lookupStatus(
    formOptions: CrewAssignmentCreateFormOptions,
    employeeId: number | null,
) {
    if (employeeId == null) {
        return null;
    }

    return (
        formOptions.employee_status_by_employee?.[String(employeeId)] ?? null
    );
}

function findBulkRowValidationError(
    errors: Record<string, string | undefined>,
): { index: number; message: string } | null {
    for (const [key, message] of Object.entries(errors)) {
        const match = /^crew\.(\d+)\.employee_id$/.exec(key);

        if (match && message) {
            return {
                index: Number(match[1]),
                message,
            };
        }
    }

    return null;
}

type UnifiedCreateFormData = BulkAddCrewFormData & {
    planned_signoff_at?: string;
    relieves_crew_assignment_id?: number | null;
    submission_intent?: 'start' | 'draft' | 'plan';
    planning_assignment_id?: number;
};

export function CrewAssignmentCreateForm({
    form_options,
    can,
    initial_row_count = 1,
    intent = null,
    prefill = null,
    planning_context = null,
    planning_back_query = null,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
    initial_row_count?: number;
    intent?: 'plan' | 'start' | null;
    prefill?: {
        employee_id?: number | null;
        vessel_id?: number | null;
        rank_id?: number | null;
        client_id?: number | null;
        planned_join_at?: string | null;
        planned_signoff_at?: string | null;
        relieves_crew_assignment_id?: number | null;
    } | null;
    planning_context?: CrewPlanningStartContext | null;
    planning_back_query?: CrewPlanningBackQuery | null;
}): ReactElement {
    const fromPlanning = planning_context !== null;
    const fromNamedPlanning =
        planning_context !== null && planning_context.employee_id !== null;
    const fromVacantPlanning =
        planning_context !== null && planning_context.employee_id === null;
    const { current_company_id: currentCompanyId } = usePage().props as {
        current_company_id?: number | null;
    };
    const initialRows = useMemo(
        () => createInitialRows(initial_row_count, planning_context, prefill),
        [initial_row_count, planning_context, prefill],
    );
    const restoredBulkSession = useMemo(() => {
        if (fromPlanning || currentCompanyId == null) {
            return null;
        }

        return loadBulkCreateSession(currentCompanyId);
    }, [currentCompanyId, fromPlanning]);
    const [rowKeys, setRowKeys] = useState<string[]>(
        () => restoredBulkSession?.rowKeys ?? initialRows.keys,
    );
    const [transferPromptOpen, setTransferPromptOpen] = useState(false);
    const [dismissedConflict, setDismissedConflict] = useState<string | null>(
        null,
    );
    const [bulkSidebarMode, setBulkSidebarMode] = useState<BulkSidebarMode>(
        () => restoredBulkSession?.bulkSidebarMode ?? 'summary',
    );
    const [previewRowKey, setPreviewRowKey] = useState<string | null>(
        () => restoredBulkSession?.previewRowKey ?? null,
    );
    const [previewFilter, setPreviewFilter] =
        useState<BulkPreviewFilter>('all');
    const [scrollFocusedRow, setScrollFocusedRow] = useState(false);

    const form = useForm<UnifiedCreateFormData>({
        client_id:
            prefill?.client_id ??
            restoredBulkSession?.form.client_id ??
            planning_context?.client_id ??
            null,
        vessel_id:
            prefill?.vessel_id ??
            restoredBulkSession?.form.vessel_id ??
            planning_context?.vessel_id ??
            null,
        planned_join_at:
            prefill?.planned_join_at ??
            restoredBulkSession?.form.planned_join_at ??
            planning_context?.planned_join_at ??
            '',
        planned_signoff_at:
            prefill?.planned_signoff_at ??
            planning_context?.planned_signoff_at ??
            '',
        relieves_crew_assignment_id:
            prefill?.relieves_crew_assignment_id ?? null,
        planned_arrival_at: planning_context?.planned_arrival_at ?? '',
        remarks:
            restoredBulkSession?.form.remarks ??
            planning_context?.remarks ??
            '',
        crew:
            restoredBulkSession?.form.crew ??
            initialRows.rows.map(
                ({ employee_id, rank_id, planned_arrival_at }) => ({
                    employee_id,
                    rank_id,
                    planned_arrival_at: planned_arrival_at ?? null,
                }),
            ),
        submission_intent:
            intent === 'plan' && can.plan
                ? 'plan'
                : can.start
                  ? 'start'
                  : 'draft',
    });

    const conflictError = (
        form.errors as unknown as Record<string, string | undefined>
    ).conflict;

    const conflictData: ConflictDialogData | null = useMemo(() => {
        if (!conflictError) {
            return null;
        }

        try {
            return JSON.parse(conflictError) as ConflictDialogData;
        } catch {
            return null;
        }
    }, [conflictError]);

    const conflictDialogOpen =
        conflictData !== null && dismissedConflict !== conflictError;

    const rows: CrewMemberRowState[] = form.data.crew.map((row, index) => ({
        ...row,
        key: rowKeys[index] ?? `crew-row-fallback-${index}`,
    }));

    const bulkMode = !fromPlanning && isBulkCreateMode(rows.length);
    const singleRow = rows[0] ?? null;
    const effectiveEmployeeId = resolveCreateEffectiveEmployeeId(
        fromPlanning,
        planning_context?.employee_id ?? null,
        singleRow?.employee_id ?? null,
    );
    const currentOnVessel =
        !bulkMode && effectiveEmployeeId
            ? (form_options.active_on_vessel_by_employee?.[
                  String(effectiveEmployeeId)
              ] ?? null)
            : null;
    const currentEmployeeStatus =
        !bulkMode && effectiveEmployeeId
            ? lookupStatus(form_options, effectiveEmployeeId)
            : null;
    const destinationVessel = form_options.vessels.find(
        (vessel) => vessel.id === form.data.vessel_id,
    );
    const canUseRecommendedTransfer = canUseManualTransferRecommendation(
        fromPlanning,
        bulkMode,
        currentOnVessel,
        form.data.vessel_id,
    );
    const transferRequiredButUnauthorized =
        !fromPlanning &&
        !bulkMode &&
        (currentEmployeeStatus?.status === 'on_vessel' ||
            currentOnVessel !== null) &&
        form.data.vessel_id !== null &&
        currentOnVessel?.vessel_id !== form.data.vessel_id &&
        currentOnVessel?.can_transfer === false;
    const hasActiveAssignmentConflict =
        hasPlanningStartActiveAssignmentConflict(
            fromPlanning,
            bulkMode,
            currentEmployeeStatus,
            canUseRecommendedTransfer,
        );
    const planningActiveAssignmentConflict =
        fromPlanning && hasActiveAssignmentConflict && currentEmployeeStatus;

    const footerActions = resolveCreateFooterActions({
        canCreate: can.create,
        canPlan: Boolean(can.plan),
        canStart: can.start,
        crewRowCount: rows.length,
        fromPlanning,
        bulkMode,
        planningActiveAssignmentConflict: Boolean(
            planningActiveAssignmentConflict,
        ),
    });

    const bulkSummary = summarizeBulkRows(form.data.crew, (employeeId) =>
        lookupStatus(form_options, employeeId),
    );
    const { readyCount, blockedCount, incompleteCount } = bulkSummary;
    const bulkCanSubmit = canSubmitBulkBatch(bulkSummary);
    const persistBulkCreateDraft = useCallback(() => {
        if (fromPlanning || !bulkMode || currentCompanyId == null) {
            return;
        }

        saveBulkCreateSession(currentCompanyId, {
            form: {
                client_id: form.data.client_id,
                vessel_id: form.data.vessel_id,
                planned_join_at: form.data.planned_join_at,
                remarks: form.data.remarks,
                crew: form.data.crew,
            },
            rowKeys,
            bulkSidebarMode,
            previewRowKey,
        });
    }, [
        bulkMode,
        bulkSidebarMode,
        currentCompanyId,
        form.data,
        fromPlanning,
        previewRowKey,
        rowKeys,
    ]);

    const serverErrors = form.errors as Record<string, string | undefined>;
    const effectiveArrivalAt =
        form.data.planned_arrival_at ||
        form.data.crew[0]?.planned_arrival_at ||
        null;
    const formErrors: Record<string, string | undefined> = {
        ...serverErrors,
        planned_arrival_at: resolveArrivalDateDisplayError({
            serverError: serverErrors.planned_arrival_at,
            arrival: effectiveArrivalAt,
            join: form.data.planned_join_at,
        }),
    };

    form.data.crew.forEach((row, index) => {
        const key = `crew.${index}.planned_arrival_at`;
        formErrors[key] = resolveArrivalDateDisplayError({
            serverError: serverErrors[key],
            arrival: row.planned_arrival_at,
            join: form.data.planned_join_at,
        });
    });
    const backHref = fromPlanning
        ? crewPlanningIndex.url({
              query: planning_back_query ?? undefined,
          })
        : can.view
          ? crewAssignmentsIndex.url()
          : dashboard.url();
    const backLabel = fromPlanning
        ? 'Back to Crew Planning'
        : can.view
          ? 'Back to Crew Assignments'
          : 'Back to Dashboard';
    const readinessPermissions = {
        view: can.view,
        update: can.update,
        perform_movement: can.perform_movement,
        cancel: can.cancel,
        view_planning: can.view_planning,
    };
    const transferPrefill = {
        vessel_id: form.data.vessel_id,
        rank_id: singleRow?.rank_id ?? null,
        client_id: form.data.client_id,
    };
    const bulkRowValidationError = findBulkRowValidationError(formErrors);
    const bulkBatchError =
        bulkFieldError(formErrors, 'error') ??
        bulkRowValidationError?.message ??
        null;
    const bulkHelperText = bulkMode ? bulkStartHelperText(bulkSummary) : null;

    const reviewBulkRow = useCallback(
        (rowKey: string, options?: { scroll?: boolean }) => {
            setBulkSidebarMode('preview');
            setPreviewRowKey(rowKey);
            setScrollFocusedRow(options?.scroll ?? false);
        },
        [],
    );

    const handleRemoveRow = useCallback(
        (index: number) => {
            const removedKey = rowKeys[index];
            const nextKeys = rowKeys.filter(
                (_, rowIndex) => rowIndex !== index,
            );
            let nextCrew = form.data.crew.filter(
                (_, rowIndex) => rowIndex !== index,
            );
            let nextKeysResolved = nextKeys;
            let insertedBlankRow = false;

            if (nextCrew.length === 0) {
                insertedBlankRow = true;
                const blankRow = newCrewRow();
                nextCrew = [
                    {
                        employee_id: blankRow.employee_id,
                        rank_id: blankRow.rank_id,
                    },
                ];
                nextKeysResolved = [blankRow.key];
            }

            const nextRows: CrewMemberRowState[] = nextCrew.map(
                (row, rowIndex) => ({
                    ...row,
                    key:
                        nextKeysResolved[rowIndex] ??
                        `crew-row-fallback-${rowIndex}`,
                }),
            );

            setRowKeys(nextKeysResolved);
            form.setData('crew', nextCrew);

            if (insertedBlankRow) {
                setBulkSidebarMode('summary');
                setPreviewRowKey(null);
                setScrollFocusedRow(false);

                return;
            }

            if (previewRowKey === removedKey) {
                const nextPreviewKey = resolveNextPreviewRowKey(
                    nextRows,
                    form_options,
                    index,
                    previewFilter,
                );

                setPreviewRowKey(nextPreviewKey);

                if (nextPreviewKey === null) {
                    setBulkSidebarMode('summary');
                }
            }
        },
        [form, form_options, previewFilter, previewRowKey, rowKeys],
    );

    const handleRemoveBlockedRows = useCallback(() => {
        const removal = removeBlockedBulkRows(rows, form_options);

        if (removal === null) {
            return;
        }

        let nextRows: CrewMemberRowState[] = removal.rows;

        if (removal.ensureMinimumOneRow) {
            nextRows = [newCrewRow()];
        }

        setRowKeys(nextRows.map((row) => row.key));
        form.setData(
            'crew',
            nextRows.map(({ employee_id, rank_id, planned_arrival_at }) => ({
                employee_id,
                rank_id,
                planned_arrival_at,
            })),
        );
        setBulkSidebarMode('summary');
        setPreviewRowKey(null);
        setScrollFocusedRow(false);
    }, [form, form_options, rows]);

    const focusBulkValidationError = useCallback(
        (errors: Record<string, string | undefined>) => {
            const rowError = findBulkRowValidationError(errors);

            if (rowError === null) {
                return;
            }

            const rowKey = rows[rowError.index]?.key;

            if (rowKey) {
                setBulkSidebarMode('summary');
                setPreviewRowKey(rowKey);
                setScrollFocusedRow(true);
            }
        },
        [rows],
    );

    const renderGuidanceSidebar = (): ReactElement => {
        if (bulkMode) {
            return (
                <CrewAssignmentBulkReadinessPanel
                    rows={rows}
                    formOptions={form_options}
                    permissions={readinessPermissions}
                    summary={bulkSummary}
                    clientId={form.data.client_id}
                    vesselId={form.data.vessel_id}
                    plannedJoinAt={form.data.planned_join_at || null}
                    sidebarMode={bulkSidebarMode}
                    onSidebarModeChange={setBulkSidebarMode}
                    previewRowKey={previewRowKey}
                    onPreviewRowKeyChange={setPreviewRowKey}
                    previewFilter={previewFilter}
                    onPreviewFilterChange={setPreviewFilter}
                    onReviewRow={(rowKey) =>
                        reviewBulkRow(rowKey, { scroll: true })
                    }
                    onRemoveRow={handleRemoveRow}
                    onRemoveBlockedRows={handleRemoveBlockedRows}
                    onBeforeExternalNavigation={persistBulkCreateDraft}
                    batchError={bulkBatchError}
                />
            );
        }

        return (
            <CrewAssignmentReadinessPanel
                employeeId={effectiveEmployeeId}
                formOptions={form_options}
                permissions={readinessPermissions}
                destinationVesselId={form.data.vessel_id}
                plannedJoinAt={form.data.planned_join_at || null}
                transferPrefill={transferPrefill}
                planningEmployeeName={planning_context?.employee_name ?? null}
                planningRankName={planning_context?.rank_name ?? null}
            />
        );
    };

    const submitSingle = (intentParam: 'start' | 'draft' | 'plan'): void => {
        if (intentParam === 'start') {
            if (canUseRecommendedTransfer) {
                setTransferPromptOpen(true);

                return;
            }

            if (hasActiveAssignmentConflict) {
                return;
            }

            if (!can.start) {
                return;
            }
        }

        if (intentParam === 'plan' && !can.plan) {
            return;
        }

        const row = form.data.crew[0];
        form.setData('submission_intent', intentParam);

        form.transform((): Record<string, unknown> => {
            if (fromNamedPlanning) {
                return {
                    planned_arrival_at: form.data.planned_arrival_at || null,
                    remarks: form.data.remarks,
                    submission_intent: intentParam,
                    planning_assignment_id:
                        planning_context?.planning_assignment_id ?? undefined,
                };
            }

            return {
                employee_id: row?.employee_id ?? null,
                rank_id: row?.rank_id ?? null,
                client_id: form.data.client_id,
                vessel_id: form.data.vessel_id,
                planned_join_at: form.data.planned_join_at,
                planned_signoff_at: form.data.planned_signoff_at || null,
                relieves_crew_assignment_id:
                    form.data.relieves_crew_assignment_id || null,
                planned_arrival_at:
                    row?.planned_arrival_at ||
                    form.data.planned_arrival_at ||
                    null,
                remarks: form.data.remarks,
                submission_intent: intentParam,
                planning_assignment_id:
                    planning_context?.planning_assignment_id ?? undefined,
            };
        });

        const postUrl = storeAssignment.url();

        form.post(postUrl, {
            onFinish: () => form.transform((data) => data),
        });
    };

    const submitBulk = (): void => {
        if (!can.start || !bulkCanSubmit) {
            return;
        }

        form.transform((data) => ({
            client_id: data.client_id,
            vessel_id: data.vessel_id,
            planned_join_at: data.planned_join_at,
            remarks: data.remarks,
            crew: data.crew.map((row) => ({
                employee_id: row.employee_id,
                rank_id: row.rank_id,
                planned_arrival_at: row.planned_arrival_at || null,
            })),
        }));

        form.post(bulkStore.url(), {
            onSuccess: () => {
                clearBulkCreateSession();
            },
            onError: (errors) => {
                focusBulkValidationError(
                    errors as Record<string, string | undefined>,
                );
            },
            onFinish: () => form.transform((data) => data),
        });
    };

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();

        if (resolveCreateSubmitRoute(rows.length) === 'bulk') {
            submitBulk();

            return;
        }

        if (intent === 'plan' && can.plan) {
            submitSingle('plan');

            return;
        }

        submitSingle(fromPlanning || can.start ? 'start' : 'draft');
    };

    const saveDraft = (): void => {
        submitSingle('draft');
    };

    return (
        <Main>
            <DetailsHeader
                kicker={
                    fromPlanning || intent === 'plan'
                        ? 'Crew Planning'
                        : 'Crew Assignments'
                }
                title={
                    intent === 'plan'
                        ? 'Plan Crew Assignment'
                        : 'Start Crew Assignment'
                }
                description={
                    intent === 'plan'
                        ? 'Record planned crew reservation for scheduling and Gantt overview.'
                        : fromPlanning
                          ? 'Review planning details and start the operational mobilisation cycle.'
                          : 'Record crew operational positions and start the mobilisation cycle.'
                }
                backHref={backHref}
                backLabel={backLabel}
            />

            <div className="mx-auto max-w-7xl pb-24">
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_19rem] lg:gap-5 xl:grid-cols-[minmax(0,1fr)_21rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-4 lg:space-y-5">
                        <div className="rounded-xl border border-sky-500/35 bg-sky-500/10 p-3.5">
                            <div className="flex gap-3">
                                <Info
                                    className="mt-0.5 size-4 shrink-0 text-sky-700 dark:text-sky-300"
                                    aria-hidden
                                />
                                <div className="space-y-0.5 text-sm text-sky-900 dark:text-sky-100">
                                    {fromPlanning ? (
                                        <>
                                            <p className="font-medium">
                                                Planning values are forecasts
                                                only. Actual movement timestamps
                                                are recorded when you confirm
                                                Start Assignment.
                                            </p>
                                            <p className="text-xs text-sky-900/80 dark:text-sky-200/80">
                                                Expected Vessel Join stays a
                                                forecast. The assignment start
                                                time uses the trusted server
                                                submit time.
                                            </p>
                                        </>
                                    ) : (
                                        <>
                                            <p className="font-medium">
                                                Start one crew member, or add
                                                more to start several
                                                assignments with the same
                                                mobilisation details.
                                            </p>
                                            <p className="text-xs text-sky-900/80 dark:text-sky-200/80">
                                                Save as Draft remains available
                                                for a single crew member. Future
                                                mobilisation belongs in Crew
                                                Planning.
                                            </p>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>

                        <Card className="border-border/80 dark:border-white/10">
                            <CardContent className="p-5 lg:p-6">
                                <form
                                    onSubmit={handleSubmit}
                                    className="space-y-8"
                                >
                                    {fromNamedPlanning && planning_context ? (
                                        <>
                                            <PlanningStartAuthoritativeFields
                                                context={planning_context}
                                            />
                                            {planningActiveAssignmentConflict ? (
                                                <PlanningStartActiveAssignmentConflict
                                                    planningContext={
                                                        planning_context
                                                    }
                                                    employeeStatus={
                                                        currentEmployeeStatus
                                                    }
                                                    activeOnVessel={
                                                        currentOnVessel
                                                    }
                                                    destinationVesselId={
                                                        form.data.vessel_id
                                                    }
                                                    canViewAssignment={can.view}
                                                />
                                            ) : null}
                                            <div className="space-y-2">
                                                <Label htmlFor="planning-planned-arrival-at">
                                                    Arrival Date{' '}
                                                    <span className="font-normal text-muted-foreground">
                                                        (optional)
                                                    </span>
                                                </Label>
                                                <Input
                                                    id="planning-planned-arrival-at"
                                                    type="date"
                                                    className="h-11 sm:max-w-md"
                                                    value={
                                                        form.data
                                                            .planned_arrival_at ??
                                                        ''
                                                    }
                                                    onChange={(e) => {
                                                        clearCreateFormDateErrors(
                                                            form.clearErrors as (
                                                                ...fields: string[]
                                                            ) => void,
                                                            dependentCreateDateErrorKeys(
                                                                'planned_arrival_at',
                                                            ),
                                                        );
                                                        form.setData(
                                                            'planned_arrival_at',
                                                            e.target.value,
                                                        );
                                                    }}
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Expected date the crew
                                                    member will arrive at the
                                                    joining location. Actual
                                                    arrival is recorded later
                                                    through Record Arrival.
                                                </p>
                                                <InputError
                                                    message={
                                                        formErrors.planned_arrival_at
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            {fromVacantPlanning &&
                                            planning_context ? (
                                                <PlanningStartAuthoritativeFields
                                                    context={planning_context}
                                                />
                                            ) : null}
                                            <CrewMembersSection
                                                rows={rows}
                                                formOptions={form_options}
                                                errors={formErrors}
                                                compact={bulkMode}
                                                focusedRowKey={
                                                    bulkMode
                                                        ? previewRowKey
                                                        : null
                                                }
                                                scrollFocusedRow={
                                                    scrollFocusedRow
                                                }
                                                canAddRow={
                                                    can.start && !fromPlanning
                                                }
                                                onAddRow={() => {
                                                    const next = newCrewRow();
                                                    setRowKeys((keys) => [
                                                        ...keys,
                                                        next.key,
                                                    ]);
                                                    form.setData('crew', [
                                                        ...form.data.crew,
                                                        {
                                                            employee_id:
                                                                next.employee_id,
                                                            rank_id:
                                                                next.rank_id,
                                                        },
                                                    ]);
                                                }}
                                                onRemoveRow={handleRemoveRow}
                                                onChangeRow={(
                                                    index: number,
                                                    row: BulkAddCrewRow,
                                                ) => {
                                                    const previous =
                                                        form.data.crew[index];

                                                    if (
                                                        previous?.planned_arrival_at !==
                                                        row.planned_arrival_at
                                                    ) {
                                                        clearCreateFormDateErrors(
                                                            form.clearErrors as (
                                                                ...fields: string[]
                                                            ) => void,
                                                            dependentCreateDateErrorKeys(
                                                                'planned_arrival_at',
                                                                {
                                                                    crewIndex:
                                                                        index,
                                                                },
                                                            ),
                                                        );
                                                    }

                                                    form.setData(
                                                        'crew',
                                                        form.data.crew.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? row
                                                                    : item,
                                                        ),
                                                    );
                                                }}
                                            />

                                            <div className="lg:hidden">
                                                {renderGuidanceSidebar()}
                                            </div>
                                        </>
                                    )}

                                    {fromPlanning ? (
                                        <div className="lg:hidden">
                                            <CrewAssignmentReadinessPanel
                                                employeeId={effectiveEmployeeId}
                                                formOptions={form_options}
                                                permissions={
                                                    readinessPermissions
                                                }
                                                destinationVesselId={
                                                    form.data.vessel_id
                                                }
                                                plannedJoinAt={
                                                    form.data.planned_join_at ||
                                                    null
                                                }
                                                planningEmployeeName={
                                                    planning_context?.employee_name ??
                                                    null
                                                }
                                                planningRankName={
                                                    planning_context?.rank_name ??
                                                    null
                                                }
                                            />
                                        </div>
                                    ) : null}

                                    <CrewAssignmentCommonFields
                                        form={form}
                                        formOptions={form_options}
                                        showMasterFields={!fromPlanning}
                                        onClearDependentDateErrors={(field) => {
                                            clearCreateFormDateErrors(
                                                form.clearErrors as (
                                                    ...fields: string[]
                                                ) => void,
                                                dependentCreateDateErrorKeys(
                                                    field,
                                                    {
                                                        crewRowCount:
                                                            form.data.crew
                                                                .length,
                                                    },
                                                ),
                                            );
                                        }}
                                    />

                                    <div className="flex flex-wrap items-center gap-3 border-t border-border/60 pt-6">
                                        {footerActions.showStart ? (
                                            <Button
                                                type={
                                                    intent === 'plan'
                                                        ? 'button'
                                                        : 'submit'
                                                }
                                                variant={
                                                    intent === 'plan'
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                disabled={
                                                    form.processing ||
                                                    (bulkMode
                                                        ? !bulkCanSubmit
                                                        : hasActiveAssignmentConflict)
                                                }
                                                title={
                                                    bulkMode
                                                        ? incompleteCount > 0
                                                            ? 'Complete or remove every crew row before starting.'
                                                            : blockedCount > 0
                                                              ? 'Resolve blocked crew members before starting.'
                                                              : undefined
                                                        : transferRequiredButUnauthorized
                                                          ? 'Vessel Transfer is required for this move, but you do not have permission to perform it.'
                                                          : hasActiveAssignmentConflict
                                                            ? 'Active assignment exists — choose an action from Movement Guidance.'
                                                            : undefined
                                                }
                                                className="h-11 rounded-xl px-6"
                                                onClick={() => {
                                                    if (intent === 'plan') {
                                                        if (bulkMode) {
                                                            submitBulk();
                                                        } else {
                                                            submitSingle(
                                                                'start',
                                                            );
                                                        }
                                                    }
                                                }}
                                            >
                                                {form.processing &&
                                                form.data.submission_intent ===
                                                    'start' ? (
                                                    <Spinner className="mr-2" />
                                                ) : null}
                                                {bulkMode
                                                    ? bulkStartButtonLabel(
                                                          readyCount,
                                                      )
                                                    : 'Start Assignment'}
                                            </Button>
                                        ) : null}

                                        {footerActions.showPlan ? (
                                            <Button
                                                type={
                                                    intent === 'plan'
                                                        ? 'submit'
                                                        : 'button'
                                                }
                                                variant={
                                                    intent === 'plan'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className="h-11 rounded-xl px-6"
                                                disabled={form.processing}
                                                onClick={() => {
                                                    if (intent !== 'plan') {
                                                        submitSingle('plan');
                                                    }
                                                }}
                                            >
                                                {form.processing &&
                                                form.data.submission_intent ===
                                                    'plan' ? (
                                                    <Spinner className="mr-2" />
                                                ) : null}
                                                Save as Planned
                                            </Button>
                                        ) : null}

                                        {footerActions.showDraft ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="h-11 rounded-xl px-6"
                                                disabled={
                                                    form.processing ||
                                                    hasActiveAssignmentConflict
                                                }
                                                onClick={saveDraft}
                                            >
                                                {form.processing &&
                                                form.data.submission_intent ===
                                                    'draft' ? (
                                                    <Spinner className="mr-2" />
                                                ) : null}
                                                Save Draft
                                            </Button>
                                        ) : null}

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="h-11 rounded-xl px-6"
                                            onClick={() =>
                                                router.visit(backHref)
                                            }
                                        >
                                            Cancel
                                        </Button>

                                        {!can.start ? (
                                            <p className="w-full text-xs font-medium text-amber-700 dark:text-amber-300">
                                                Starting an operational
                                                assignment requires movement
                                                permission. You can still save a
                                                Draft for one crew member, or
                                                ask an authorized Operations
                                                user to start the assignment.
                                            </p>
                                        ) : null}

                                        {bulkHelperText ? (
                                            <p className="w-full text-xs font-medium text-destructive">
                                                {bulkHelperText}
                                            </p>
                                        ) : null}

                                        {!bulkMode &&
                                        transferRequiredButUnauthorized ? (
                                            <p className="w-full text-xs font-medium text-amber-700 dark:text-amber-300">
                                                Vessel Transfer is required for
                                                this move. You do not have
                                                permission to perform it; ask an
                                                authorized Operations user to
                                                continue.
                                            </p>
                                        ) : !bulkMode &&
                                          hasActiveAssignmentConflict &&
                                          !planningActiveAssignmentConflict &&
                                          !formErrors.error ? (
                                            <p className="w-full text-xs font-medium text-destructive">
                                                Active assignment exists —
                                                choose an action from Movement
                                                Guidance.
                                            </p>
                                        ) : null}

                                        <InputError
                                            message={
                                                bulkFieldError(
                                                    formErrors,
                                                    'error',
                                                ) ?? formErrors.error
                                            }
                                            className="w-full"
                                        />
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="hidden lg:block">
                        <div className="sticky top-24">
                            {renderGuidanceSidebar()}
                        </div>
                    </div>
                </div>
            </div>

            {!bulkMode && !fromPlanning ? (
                <VesselTransferRecommendationDialog
                    open={transferPromptOpen}
                    onOpenChange={setTransferPromptOpen}
                    current={currentOnVessel}
                    destinationVesselName={destinationVessel?.name}
                    prefill={{
                        vessel_id: form.data.vessel_id,
                        rank_id: singleRow?.rank_id ?? null,
                        client_id: form.data.client_id,
                    }}
                />
            ) : null}

            <CrewAssignmentConflictDialog
                open={conflictDialogOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        setDismissedConflict(conflictError ?? null);
                    }
                }}
                conflict={conflictData}
                employeeName={
                    form_options.employees.find(
                        (e) => e.id === form.data.crew[0]?.employee_id,
                    )?.name
                }
                can={can}
                onAdjustDates={() => {
                    document.getElementById('planned_join_at')?.focus();
                }}
            />
        </Main>
    );
}
