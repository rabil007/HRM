import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { openTransferVessel } from '@/features/organization/crew/actions/vessel-transfer-recommendation-dialog';
import type { CrewMemberRowState } from '@/features/organization/crew/components/crew-members-section';
import {
    GuidanceActionButton,
    GuidanceEmployeeIdentity,
    GuidanceWarning,
    WhyStartBlockedHelp,
} from '@/features/organization/crew/components/crew-movement-guidance-primitives';
import { CrewMovementJourneyIndicator } from '@/features/organization/crew/components/crew-movement-journey-indicator';
import type { ReadinessAction } from '@/features/organization/crew/lib/assignment-readiness-guidance';
import { readinessAdvisoryClassName } from '@/features/organization/crew/lib/assignment-readiness-guidance';
import type {
    BulkPreviewFilter,
    BulkSidebarMode,
} from '@/features/organization/crew/lib/bulk-readiness-preview';
import {
    buildBulkAttentionList,
    buildBulkEmployeePreviewGuidance,
    buildBulkPreviewRows,
    buildBulkTargetSummary,
    bulkPreviewBlockedWarning,
    bulkReadyLabel,
    resolvePreviewIndex,
} from '@/features/organization/crew/lib/bulk-readiness-preview';
import type { BulkRowSummary } from '@/features/organization/crew/lib/bulk-row-status';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

function BulkReadinessHeader(): ReactElement {
    return (
        <div>
            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                Bulk Readiness
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
                Batch overview and per-employee inspection.
            </p>
        </div>
    );
}

function SegmentedControl<T extends string>({
    value,
    options,
    onChange,
    ariaLabel,
}: {
    value: T;
    options: Array<{ value: T; label: string }>;
    onChange: (value: T) => void;
    ariaLabel: string;
}): ReactElement {
    return (
        <div
            className="inline-flex w-full rounded-lg border border-border/60 bg-muted/10 p-0.5"
            role="tablist"
            aria-label={ariaLabel}
        >
            {options.map((option) => {
                const selected = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        className={cn(
                            'flex-1 rounded-md px-2 py-1.5 text-[11px] font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            selected
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        onClick={() => onChange(option.value)}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

function CountBadge({
    label,
    count,
    tone,
}: {
    label: string;
    count: number;
    tone: 'success' | 'destructive' | 'neutral';
}): ReactElement {
    return (
        <div
            className={cn(
                'flex items-center justify-between rounded-md border px-2 py-1 text-xs',
                tone === 'success' &&
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100',
                tone === 'destructive' &&
                    'border-destructive/30 bg-destructive/10 text-destructive',
                tone === 'neutral' &&
                    'border-amber-500/30 bg-amber-500/10 text-amber-950 dark:text-amber-100',
            )}
        >
            <span>{label}</span>
            <span className="font-semibold tabular-nums">{count}</span>
        </div>
    );
}

function resolveActionHref(
    action: ReadinessAction,
    assignmentId: number | null,
): string | null {
    if (assignmentId === null) {
        return action.key === 'plan_future' ? crewPlanningIndex.url() : null;
    }

    switch (action.key) {
        case 'edit_mobilisation':
            return editAssignment.url(assignmentId);
        case 'plan_future':
            return crewPlanningIndex.url();
        case 'continue_assignment':
        case 'open_assignment':
        case 'join_vessel':
        case 'return_home':
        case 'redeploy':
        case 'close_assignment':
        case 'cancel_assignment':
        case 'record_arrival':
            return showAssignment.url(assignmentId);
        default:
            return null;
    }
}

export function CrewAssignmentBulkReadinessPanel({
    rows,
    formOptions,
    permissions,
    summary,
    clientId,
    vesselId,
    plannedJoinAt,
    sidebarMode,
    onSidebarModeChange,
    previewRowKey,
    onPreviewRowKeyChange,
    previewFilter,
    onPreviewFilterChange,
    onReviewRow,
    onRemoveRow,
    onRemoveBlockedRows,
    batchError,
    className,
}: {
    rows: CrewMemberRowState[];
    formOptions: CrewAssignmentCreateFormOptions;
    permissions: Pick<
        CrewAssignmentPagePermissions,
        'view' | 'update' | 'perform_movement' | 'cancel' | 'view_planning'
    >;
    summary: BulkRowSummary;
    clientId: number | null;
    vesselId: number | null;
    plannedJoinAt: string | null;
    sidebarMode: BulkSidebarMode;
    onSidebarModeChange: (mode: BulkSidebarMode) => void;
    previewRowKey: string | null;
    onPreviewRowKeyChange: (rowKey: string | null) => void;
    previewFilter: BulkPreviewFilter;
    onPreviewFilterChange: (filter: BulkPreviewFilter) => void;
    onReviewRow: (rowKey: string) => void;
    onRemoveRow: (index: number) => void;
    onRemoveBlockedRows?: () => void;
    batchError?: string | null;
    className?: string;
}): ReactElement {
    const destinationVesselName =
        vesselId != null
            ? (formOptions.vessels.find((vessel) => vessel.id === vesselId)
                  ?.name ?? null)
            : null;
    const attentionItems = useMemo(
        () => buildBulkAttentionList(rows, formOptions),
        [rows, formOptions],
    );
    const targetSummary = buildBulkTargetSummary({
        clientId,
        vesselId,
        plannedJoinAt,
        formOptions,
    });
    const previewRows = useMemo(
        () => buildBulkPreviewRows(rows, formOptions, previewFilter),
        [rows, formOptions, previewFilter],
    );
    const previewIndex = resolvePreviewIndex(previewRows, previewRowKey);
    const currentPreview = previewRows[previewIndex] ?? null;

    useEffect(() => {
        if (previewRows.length === 0) {
            if (previewRowKey !== null) {
                onPreviewRowKeyChange(null);
            }

            if (sidebarMode === 'preview') {
                onSidebarModeChange('summary');
            }

            return;
        }

        if (
            previewRowKey === null ||
            !previewRows.some((item) => item.rowKey === previewRowKey)
        ) {
            onPreviewRowKeyChange(previewRows[0]?.rowKey ?? null);
        }
    }, [
        previewRows,
        previewRowKey,
        onPreviewRowKeyChange,
        sidebarMode,
        onSidebarModeChange,
    ]);

    const employee =
        currentPreview?.row.employee_id != null
            ? formOptions.employees.find(
                  (item) => item.id === currentPreview.row.employee_id,
              )
            : null;
    const rankName =
        currentPreview?.row.rank_id != null
            ? (formOptions.ranks.find(
                  (rank) => rank.id === currentPreview.row.rank_id,
              )?.name ?? null)
            : null;
    const assignmentId =
        currentPreview?.status?.assignment_id ??
        currentPreview?.activeOnVessel?.assignment_id ??
        null;
    const guidance =
        currentPreview != null
            ? buildBulkEmployeePreviewGuidance(currentPreview, {
                  formOptions,
                  permissions,
                  destinationVesselId: vesselId,
                  destinationVesselName,
                  plannedJoinAt,
              })
            : null;
    const blockedWarning = currentPreview
        ? bulkPreviewBlockedWarning(currentPreview.state)
        : null;
    const readyOthersLabel = bulkReadyLabel(summary);
    const transferPrefill = {
        vessel_id: vesselId,
        rank_id: currentPreview?.row.rank_id ?? null,
        client_id: clientId,
    };

    const navigatePreview = (direction: -1 | 1): void => {
        if (previewRows.length === 0 || currentPreview == null) {
            return;
        }

        const nextIndex =
            (previewIndex + direction + previewRows.length) %
            previewRows.length;
        const nextRow = previewRows[nextIndex];

        if (nextRow) {
            onPreviewRowKeyChange(nextRow.rowKey);
            onReviewRow(nextRow.rowKey);
        }
    };

    return (
        <aside
            className={cn('rounded-xl border glass-card p-3.5', className)}
            aria-label="Bulk Readiness"
            aria-live="polite"
        >
            <BulkReadinessHeader />

            <div className="mt-2.5 space-y-3">
                <SegmentedControl
                    value={sidebarMode}
                    ariaLabel="Bulk readiness view"
                    options={[
                        { value: 'summary', label: 'Summary' },
                        { value: 'preview', label: 'Preview' },
                    ]}
                    onChange={onSidebarModeChange}
                />

                {batchError ? <GuidanceWarning message={batchError} /> : null}

                {sidebarMode === 'summary' ? (
                    <>
                        <div>
                            <p className="text-sm font-semibold">
                                {rows.length} crew selected
                            </p>
                            <div className="mt-2 grid gap-1.5">
                                <CountBadge
                                    label="Ready"
                                    count={summary.readyCount}
                                    tone="success"
                                />
                                <CountBadge
                                    label="Blocked"
                                    count={summary.blockedCount}
                                    tone="destructive"
                                />
                                <CountBadge
                                    label="Incomplete"
                                    count={summary.incompleteCount}
                                    tone="neutral"
                                />
                            </div>
                        </div>

                        {targetSummary.clientName ||
                        targetSummary.vesselName ||
                        targetSummary.plannedJoinLabel ? (
                            <div className="rounded-lg border border-border/60 bg-muted/10 px-2.5 py-2 text-xs">
                                <p className="font-semibold tracking-wide text-muted-foreground uppercase">
                                    Target
                                </p>
                                {targetSummary.clientName ? (
                                    <p className="mt-1 font-medium">
                                        {targetSummary.clientName}
                                    </p>
                                ) : null}
                                {targetSummary.vesselName ? (
                                    <p
                                        className={cn(
                                            'font-medium',
                                            !targetSummary.clientName && 'mt-1',
                                        )}
                                    >
                                        {targetSummary.vesselName}
                                    </p>
                                ) : null}
                                {targetSummary.plannedJoinLabel ? (
                                    <p className="mt-0.5 text-muted-foreground">
                                        Expected Join ·{' '}
                                        {formatDisplayDate(
                                            targetSummary.plannedJoinLabel,
                                        )}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        {attentionItems.length > 0 ? (
                            <div className="space-y-2">
                                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    Needs attention
                                </p>
                                {attentionItems.map((item) => (
                                    <div
                                        key={item.rowKey}
                                        className="rounded-lg border border-border/60 bg-muted/10 px-2.5 py-2 text-xs"
                                    >
                                        <p className="font-semibold uppercase">
                                            {item.employeeName}
                                        </p>
                                        <p className="mt-0.5 text-muted-foreground">
                                            {item.phaseLabel}
                                            {item.summaryLine
                                                ? ` · ${item.summaryLine}`
                                                : ''}
                                        </p>
                                        <p className="mt-1 leading-relaxed">
                                            {item.message}
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="mt-2 h-7 rounded-lg px-2.5 text-xs"
                                            onClick={() =>
                                                onReviewRow(item.rowKey)
                                            }
                                        >
                                            Review
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : null}

                        {readyOthersLabel ? (
                            <p className="text-xs text-emerald-700 dark:text-emerald-300">
                                ✓ {readyOthersLabel}
                            </p>
                        ) : null}

                        {summary.blockedCount > 0 && onRemoveBlockedRows ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 w-full rounded-lg text-xs"
                                onClick={onRemoveBlockedRows}
                            >
                                Remove {summary.blockedCount} Blocked
                            </Button>
                        ) : null}
                    </>
                ) : (
                    <>
                        <div className="flex items-center justify-between gap-2">
                            <SegmentedControl
                                value={previewFilter}
                                ariaLabel="Preview filter"
                                options={[
                                    { value: 'all', label: 'All Crew' },
                                    { value: 'issues', label: 'Issues Only' },
                                ]}
                                onChange={onPreviewFilterChange}
                            />
                        </div>

                        <div className="flex items-center justify-between gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-8 shrink-0 rounded-lg"
                                aria-label="Previous crew"
                                disabled={previewRows.length <= 1}
                                onClick={() => navigatePreview(-1)}
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <p className="text-xs font-medium text-muted-foreground tabular-nums">
                                {previewRows.length === 0
                                    ? '0 of 0'
                                    : `${previewIndex + 1} of ${previewRows.length}`}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="size-8 shrink-0 rounded-lg"
                                aria-label="Next crew"
                                disabled={previewRows.length <= 1}
                                onClick={() => navigatePreview(1)}
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>

                        {currentPreview?.state === 'incomplete' ? (
                            <div className="space-y-2 rounded-lg border border-dashed border-border/70 bg-muted/10 px-2.5 py-3 text-xs">
                                <p className="font-semibold">
                                    Row {currentPreview.index + 1}
                                </p>
                                <p className="text-muted-foreground">
                                    Incomplete
                                </p>
                                <p>Select an employee or remove this row.</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 rounded-lg text-xs"
                                        onClick={() =>
                                            onReviewRow(currentPreview.rowKey)
                                        }
                                    >
                                        Focus Row
                                    </Button>
                                    {rows.length > 1 ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-7 rounded-lg text-xs"
                                            onClick={() =>
                                                onRemoveRow(
                                                    currentPreview.index,
                                                )
                                            }
                                        >
                                            Remove Row
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        ) : currentPreview && employee ? (
                            <div className="space-y-2">
                                <GuidanceEmployeeIdentity
                                    name={employee.name}
                                    employeeNo={employee.employee_no}
                                    rankName={rankName}
                                    nationalityName={employee.nationality_name}
                                    image={employee.image}
                                />

                                {guidance ? (
                                    <>
                                        <div>
                                            <p className="text-xs font-semibold">
                                                {guidance.phaseLabel}
                                            </p>
                                            {guidance.summaryLine ? (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {guidance.summaryLine}
                                                </p>
                                            ) : null}
                                        </div>

                                        {currentPreview.status
                                            ?.current_phase ? (
                                            <CrewMovementJourneyIndicator
                                                currentPhaseCode={
                                                    currentPreview.status
                                                        .current_phase
                                                }
                                            />
                                        ) : null}

                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                            {guidance.explanation}
                                        </p>

                                        {currentPreview.state === 'ready' ? (
                                            <p className="text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                                ✓ Ready for this batch
                                            </p>
                                        ) : null}

                                        {currentPreview.state === 'blocked' &&
                                        guidance.actions.length > 0 ? (
                                            <div className="space-y-1.5">
                                                {guidance.actions.map(
                                                    (action) => (
                                                        <GuidanceActionButton
                                                            key={`${action.key}-${action.label}`}
                                                            action={action}
                                                            href={resolveActionHref(
                                                                action,
                                                                assignmentId,
                                                            )}
                                                            onClick={
                                                                action.kind ===
                                                                    'transfer' &&
                                                                currentPreview.activeOnVessel
                                                                    ? () =>
                                                                          openTransferVessel(
                                                                              currentPreview.activeOnVessel!,
                                                                              transferPrefill,
                                                                          )
                                                                    : undefined
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        ) : null}

                                        {guidance.destinationAdvisory ? (
                                            <div
                                                className={cn(
                                                    'rounded-lg border px-2.5 py-2 text-xs',
                                                    readinessAdvisoryClassName(
                                                        guidance
                                                            .destinationAdvisory
                                                            .severity,
                                                    ),
                                                )}
                                            >
                                                <p className="font-semibold">
                                                    {
                                                        guidance
                                                            .destinationAdvisory
                                                            .title
                                                    }
                                                </p>
                                                <p className="mt-0.5 opacity-90">
                                                    {
                                                        guidance
                                                            .destinationAdvisory
                                                            .message
                                                    }
                                                </p>
                                            </div>
                                        ) : null}

                                        {blockedWarning ? (
                                            <GuidanceWarning
                                                message={blockedWarning}
                                            />
                                        ) : null}

                                        {guidance.showWhyBlocked &&
                                        currentPreview.state === 'blocked' ? (
                                            <WhyStartBlockedHelp />
                                        ) : null}
                                    </>
                                ) : null}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="h-8 w-full rounded-lg text-xs"
                                    onClick={() =>
                                        onRemoveRow(currentPreview.index)
                                    }
                                    disabled={rows.length <= 1}
                                >
                                    Remove from Batch
                                </Button>
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                No crew rows to preview.
                            </p>
                        )}
                    </>
                )}
            </div>
        </aside>
    );
}
