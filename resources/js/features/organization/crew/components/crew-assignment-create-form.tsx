import { Link, router, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { VesselTransferRecommendationDialog } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import { CrewAssignmentCommonFields } from '@/features/organization/crew/components/crew-assignment-common-fields';
import { CrewAssignmentReadinessPanel } from '@/features/organization/crew/components/crew-assignment-readiness-panel';
import type { CrewMemberRowState } from '@/features/organization/crew/components/crew-members-section';
import { CrewMembersSection } from '@/features/organization/crew/components/crew-members-section';
import { PlanningStartActiveAssignmentConflict } from '@/features/organization/crew/components/planning-start-active-assignment-conflict';
import { PlanningStartAuthoritativeFields } from '@/features/organization/crew/components/planning-start-authoritative-fields';
import {
    bulkFieldError,
    canSubmitBulkBatch,
    summarizeBulkRows,
} from '@/features/organization/crew/lib/bulk-row-status';
import {
    bulkStartButtonLabel,
    isBulkCreateMode,
    resolveCreateEffectiveEmployeeId,
    resolveCreateSubmitRoute,
    shouldShowSaveDraft,
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
import { formatDisplayDate } from '@/lib/format-date';
import { dashboard } from '@/routes';
import {
    bulkStore,
    index as crewAssignmentsIndex,
    store as storeAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { start as startFromPlanning } from '@/routes/organization/crew-planning/assignments';

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
        rows.push(newCrewRow());
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

type UnifiedCreateFormData = BulkAddCrewFormData & {
    submission_intent?: 'start' | 'draft';
};

export function CrewAssignmentCreateForm({
    form_options,
    can,
    initial_row_count = 1,
    planning_context = null,
    planning_back_query = null,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
    initial_row_count?: number;
    planning_context?: CrewPlanningStartContext | null;
    planning_back_query?: CrewPlanningBackQuery | null;
}): ReactElement {
    const fromPlanning = planning_context !== null;
    const initialRows = useMemo(
        () => createInitialRows(initial_row_count, planning_context),
        [initial_row_count, planning_context],
    );
    const [rowKeys, setRowKeys] = useState<string[]>(initialRows.keys);
    const [transferPromptOpen, setTransferPromptOpen] = useState(false);

    const form = useForm<UnifiedCreateFormData>({
        client_id: planning_context?.client_id ?? null,
        vessel_id: planning_context?.vessel_id ?? null,
        planned_join_at: planning_context?.planned_join_at ?? '',
        planned_arrival_at: planning_context?.planned_arrival_at ?? '',
        remarks: planning_context?.remarks ?? '',
        crew: initialRows.rows.map(
            ({ employee_id, rank_id, planned_arrival_at }) => ({
                employee_id,
                rank_id,
                planned_arrival_at: planned_arrival_at ?? null,
            }),
        ),
        submission_intent: can.start ? 'start' : 'draft',
    });

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

    const bulkSummary = summarizeBulkRows(form.data.crew, (employeeId) =>
        lookupStatus(form_options, employeeId),
    );
    const { readyCount, blockedCount, incompleteCount } = bulkSummary;
    const bulkCanSubmit = canSubmitBulkBatch(bulkSummary);
    const formErrors = form.errors as Record<string, string | undefined>;
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
    const vesselName = destinationVessel?.name ?? 'Not selected';

    const submitSingle = (intent: 'start' | 'draft'): void => {
        if (canUseRecommendedTransfer) {
            setTransferPromptOpen(true);

            return;
        }

        if (hasActiveAssignmentConflict) {
            return;
        }

        if (intent === 'start' && !can.start) {
            return;
        }

        const row = form.data.crew[0];
        form.setData('submission_intent', intent);

        form.transform(() => {
            if (fromPlanning) {
                return {
                    planned_arrival_at: form.data.planned_arrival_at || null,
                    remarks: form.data.remarks,
                };
            }

            return {
                employee_id: row?.employee_id ?? null,
                rank_id: row?.rank_id ?? null,
                client_id: form.data.client_id,
                vessel_id: form.data.vessel_id,
                planned_join_at: form.data.planned_join_at,
                planned_arrival_at:
                    row?.planned_arrival_at ||
                    form.data.planned_arrival_at ||
                    null,
                remarks: form.data.remarks,
                submission_intent: intent,
            };
        });

        const postUrl = fromPlanning
            ? startFromPlanning.url(planning_context!.planning_assignment_id)
            : storeAssignment.url();

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
            onFinish: () => form.transform((data) => data),
        });
    };

    const handleSubmit = (event: React.FormEvent): void => {
        event.preventDefault();

        if (resolveCreateSubmitRoute(rows.length) === 'bulk') {
            submitBulk();

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
                kicker={fromPlanning ? 'Crew Planning' : 'Crew Assignments'}
                title="Start Crew Assignment"
                description={
                    fromPlanning
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
                                    {fromPlanning && planning_context ? (
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
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'planned_arrival_at',
                                                            e.target.value,
                                                        )
                                                    }
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
                                                        form.errors
                                                            .planned_arrival_at
                                                    }
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <CrewMembersSection
                                                rows={rows}
                                                formOptions={form_options}
                                                errors={formErrors}
                                                compact={bulkMode}
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
                                                onRemoveRow={(index) => {
                                                    setRowKeys((keys) =>
                                                        keys.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    );
                                                    form.setData(
                                                        'crew',
                                                        form.data.crew.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    );
                                                }}
                                                onChangeRow={(
                                                    index: number,
                                                    row: BulkAddCrewRow,
                                                ) => {
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
                                                <CrewAssignmentReadinessPanel
                                                    employeeId={
                                                        effectiveEmployeeId
                                                    }
                                                    formOptions={form_options}
                                                    destinationVesselId={
                                                        form.data.vessel_id
                                                    }
                                                    bulkMode={bulkMode}
                                                />
                                            </div>
                                        </>
                                    )}

                                    {fromPlanning ? (
                                        <div className="lg:hidden">
                                            <CrewAssignmentReadinessPanel
                                                employeeId={effectiveEmployeeId}
                                                formOptions={form_options}
                                                destinationVesselId={
                                                    form.data.vessel_id
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
                                    />

                                    {bulkMode ? (
                                        <div className="rounded-xl border border-border/60 bg-muted/15 p-4 text-sm">
                                            <p className="font-semibold">
                                                {readyCount === 1
                                                    ? '1 crew member ready'
                                                    : `${readyCount} crew members ready`}
                                            </p>
                                            {incompleteCount > 0 ? (
                                                <p className="mt-1 font-medium text-destructive">
                                                    {incompleteCount === 1
                                                        ? '1 incomplete'
                                                        : `${incompleteCount} incomplete`}
                                                </p>
                                            ) : null}
                                            {blockedCount > 0 ? (
                                                <p className="mt-1 font-medium text-destructive">
                                                    {blockedCount === 1
                                                        ? '1 blocked'
                                                        : `${blockedCount} blocked`}
                                                </p>
                                            ) : null}
                                            <p className="mt-1 text-muted-foreground">
                                                Vessel: {vesselName}
                                            </p>
                                            <p className="text-muted-foreground">
                                                Expected Join:{' '}
                                                {form.data.planned_join_at
                                                    ? formatDisplayDate(
                                                          form.data
                                                              .planned_join_at,
                                                      )
                                                    : 'Not set'}
                                            </p>
                                        </div>
                                    ) : null}

                                    <div className="flex flex-wrap items-center gap-3 border-t border-border/60 pt-6">
                                        {can.start &&
                                        !planningActiveAssignmentConflict ? (
                                            <Button
                                                type="submit"
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
                                                            ? 'This employee already has an active Crew Assignment. Resolve the conflict above before creating a new one.'
                                                            : undefined
                                                }
                                                className="h-11 rounded-xl px-6"
                                            >
                                                {form.processing ? (
                                                    <Spinner className="mr-2" />
                                                ) : null}
                                                {bulkMode
                                                    ? bulkStartButtonLabel(
                                                          readyCount,
                                                      )
                                                    : 'Start Assignment'}
                                            </Button>
                                        ) : null}

                                        {shouldShowSaveDraft(rows.length) &&
                                        !fromPlanning ? (
                                            <Button
                                                type="button"
                                                variant={
                                                    can.start
                                                        ? 'outline'
                                                        : 'default'
                                                }
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
                                                Save as Draft
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

                                        {can.view_planning ? (
                                            <p className="w-full text-xs text-muted-foreground">
                                                Planning this for later?{' '}
                                                <Link
                                                    href={crewPlanningIndex.url()}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    Plan Crew Instead →
                                                </Link>
                                            </p>
                                        ) : null}

                                        {bulkMode && incompleteCount > 0 ? (
                                            <p className="w-full text-sm font-medium text-destructive">
                                                {incompleteCount === 1
                                                    ? '1 crew member incomplete. Select an employee or remove this row.'
                                                    : `${incompleteCount} crew members incomplete. Select an employee or remove each highlighted row.`}
                                            </p>
                                        ) : null}

                                        {bulkMode && blockedCount > 0 ? (
                                            <p className="w-full text-sm font-medium text-destructive">
                                                {blockedCount === 1
                                                    ? '1 crew member cannot be started. Resolve the highlighted row before continuing.'
                                                    : `${blockedCount} crew members cannot be started. Resolve the highlighted rows before continuing.`}
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
                                                This employee already has an
                                                active Crew Assignment. Resolve
                                                the conflict above before
                                                creating a new one.
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
                            <CrewAssignmentReadinessPanel
                                employeeId={effectiveEmployeeId}
                                formOptions={form_options}
                                destinationVesselId={form.data.vessel_id}
                                bulkMode={bulkMode}
                                planningEmployeeName={
                                    planning_context?.employee_name ?? null
                                }
                                planningRankName={
                                    planning_context?.rank_name ?? null
                                }
                            />
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
        </Main>
    );
}
