import { useForm, useHttp } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import BulkVoidCrewAssignmentsController from '@/actions/App/Http/Controllers/Organization/BulkVoidCrewAssignmentsController';
import PreviewVoidCrewAssignmentsController from '@/actions/App/Http/Controllers/Organization/PreviewVoidCrewAssignmentsController';
import VoidCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/VoidCrewAssignmentController';
import { ActionImpactPreview } from '@/components/action-impact-preview';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    CrewAssignmentDetail,
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    VoidImpactPreview,
} from '../types';

export type VoidableAssignment = Pick<
    CrewAssignmentDetail | CrewAssignmentListItem,
    'id' | 'assignment_no'
> & {
    employee?: { name?: string | null } | null;
    current_phase?: {
        code: string;
        label: string;
        status?: string | null;
    } | null;
    status_label?: string | null;
};

export function VoidErroneousAssignmentDialog({
    open,
    onOpenChange,
    assignment,
    assignments,
    can,
    onSuccess,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignment?: VoidableAssignment | null;
    assignments?: VoidableAssignment[];
    can?: Partial<CrewAssignmentPagePermissions>;
    onSuccess?: () => void;
}) {
    const targets = useMemo(() => {
        if (assignments && assignments.length > 0) {
            return assignments;
        }

        if (assignment) {
            return [assignment];
        }

        return [];
    }, [assignment, assignments]);

    const targetIds = useMemo(() => targets.map((t) => t.id), [targets]);
    const targetIdsKey = targetIds.join(',');

    const http = useHttp<{ assignment_ids: number[] }, VoidImpactPreview>({
        assignment_ids: [],
    });

    const [preview, setPreview] = useState<VoidImpactPreview | null>(null);
    const [previewTargetKey, setPreviewTargetKey] = useState<string | null>(
        null,
    );
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewRefreshKey, setPreviewRefreshKey] = useState(0);

    const form = useForm<{
        assignment_ids: number[];
        void_reason: string;
        delete_sea_service: boolean;
        delete_training: boolean;
    }>({
        assignment_ids: targetIds,
        void_reason: '',
        delete_sea_service: false,
        delete_training: false,
    });

    const bagErrors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open || targets.length === 0) {
            setPreview(null);
            setPreviewTargetKey(null);
            setPreviewError(null);
            setLoadingPreview(false);

            return;
        }

        let cancelled = false;
        setLoadingPreview(true);
        setPreviewError(null);
        setPreview(null);
        setPreviewTargetKey(null);

        form.setData((prev) => ({
            ...prev,
            assignment_ids: targetIds,
            delete_sea_service: false,
            delete_training: false,
        }));

        http.setData({ assignment_ids: targetIds });
        http.post(PreviewVoidCrewAssignmentsController.url())
            .then((res) => {
                if (!cancelled) {
                    setPreview(res);
                    setPreviewTargetKey(targetIdsKey);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPreview(null);
                    setPreviewTargetKey(null);
                    setPreviewError(
                        targets.length === 1
                            ? 'Unable to load impact preview. The assignment cannot be deleted until the safety check succeeds.'
                            : 'Unable to load impact preview. The assignments cannot be deleted until the safety check succeeds.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingPreview(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // useHttp() and form return new objects each render; keep dependencies stable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, targetIdsKey, previewRefreshKey]);

    const isSingle = targets.length === 1;
    const singleTarget = targets[0] ?? null;

    const canDeleteSeaService =
        preview?.can_delete_sea_service ?? can?.delete_sea_service ?? false;
    const canDeleteTraining =
        preview?.can_delete_training ?? can?.delete_training ?? false;

    const hasSeaService = preview?.has_sea_service ?? false;
    const hasTraining = preview?.has_training ?? false;
    const hasProtectedBlockers = preview?.has_protected_blockers ?? false;

    const isBlockedByMissingSeaServiceCleanup =
        hasSeaService && !form.data.delete_sea_service;

    const hasValidPreview =
        preview !== null &&
        previewError === null &&
        previewTargetKey === targetIdsKey;

    const isSubmitDisabled =
        form.processing ||
        loadingPreview ||
        !hasValidPreview ||
        !form.data.void_reason.trim() ||
        hasProtectedBlockers ||
        isBlockedByMissingSeaServiceCleanup;

    const submit = (): void => {
        if (isSubmitDisabled || !hasValidPreview || targets.length === 0) {
            return;
        }

        if (isSingle && singleTarget) {
            form.post(VoidCrewAssignmentController.url(singleTarget.id), {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    form.clearErrors();
                    onOpenChange(false);
                    onSuccess?.();
                },
            });
        } else {
            form.post(BulkVoidCrewAssignmentsController.url(), {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    form.clearErrors();
                    onOpenChange(false);
                    onSuccess?.();
                },
            });
        }
    };

    const dialogTitle = isSingle
        ? 'Delete Crew Assignment'
        : `Delete ${targets.length} Crew Assignments`;

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                    setPreview(null);
                    setPreviewTargetKey(null);
                    setPreviewError(null);
                    setLoadingPreview(false);
                }

                onOpenChange(next);
            }}
        >
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>{dialogTitle}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {isSingle ? (
                            <>
                                You are about to remove this assignment from
                                active Crew Operations while retaining audit
                                history.
                            </>
                        ) : (
                            <>
                                {targets.length} assignments are about to be
                                removed from active Crew Operations while
                                retaining audit history.
                            </>
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {isSingle && singleTarget ? (
                    <ActionImpactPreview
                        severity="destructive"
                        subject={[
                            singleTarget.assignment_no,
                            singleTarget.employee?.name,
                        ]
                            .filter(Boolean)
                            .join('\n')}
                        currentState={
                            singleTarget.current_phase
                                ? `${singleTarget.current_phase.code.toUpperCase()} · ${singleTarget.current_phase.label}`
                                : undefined
                        }
                        impacts={[
                            'The assignment is marked voided and removed from active operational use.',
                            'Audit history for the assignment remains retained.',
                            'Derived planning bars linked to this assignment are cleaned up according to existing void rules.',
                        ]}
                        warning="This assignment cannot be voided if it has already affected protected payroll, sea service, or a linked assignment. Use the appropriate correction or reversal workflow instead."
                    />
                ) : targets.length > 1 ? (
                    <ActionImpactPreview
                        severity="destructive"
                        subject={`${targets.length} assignments selected`}
                        impacts={[
                            `${targets.length} assignments will be removed from active operational use while retaining audit history.`,
                            'Derived planning bars linked to these assignments are cleaned up according to existing void rules.',
                        ]}
                        warning="Assignments that have affected protected payroll, sea service, or linked assignments cannot be deleted. Use the appropriate correction or reversal workflow instead."
                    />
                ) : null}

                {loadingPreview ? (
                    <div className="flex items-center justify-center gap-2 py-3 text-xs text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" />
                        <span>Checking linked records and dependencies...</span>
                    </div>
                ) : null}

                {previewError ? (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                        <span className="leading-relaxed">{previewError}</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setPreviewRefreshKey((k) => k + 1)}
                            disabled={loadingPreview}
                            className="h-7 shrink-0 text-xs text-destructive hover:bg-destructive/10"
                        >
                            Retry safety check
                        </Button>
                    </div>
                ) : null}

                {hasProtectedBlockers && preview ? (
                    <div className="space-y-1.5 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                        <div className="flex items-center gap-1.5 font-semibold">
                            <AlertTriangle className="h-4 w-4 shrink-0" />
                            <span>
                                Cannot delete blocked assignment
                                {preview.blocked_assignment_nos.length === 1
                                    ? ''
                                    : 's'}
                            </span>
                        </div>
                        <p>
                            {preview.blocked_assignment_nos.length === 1
                                ? `Cannot delete ${preview.blocked_assignment_nos[0]}. This assignment has already affected protected payroll, accommodation history, or a linked assignment.`
                                : `${preview.blocked_assignment_nos.join(', ')} have already affected protected payroll, accommodation history, or linked assignments.`}
                        </p>
                        <p className="text-[11px] opacity-90">
                            Use the appropriate correction or reversal workflow
                            instead.
                        </p>
                    </div>
                ) : null}

                {!loadingPreview &&
                preview &&
                (preview.total_sea_service_records > 0 ||
                    preview.total_training_records > 0) ? (
                    <div className="space-y-3 rounded-xl border border-border/70 bg-card/60 p-3 text-xs">
                        <div className="font-semibold text-foreground">
                            Linked records detected
                        </div>

                        <div className="space-y-1.5">
                            {preview.total_sea_service_records > 0 ? (
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>Sea Service</span>
                                    <span className="font-medium text-foreground">
                                        {preview.total_sea_service_records}{' '}
                                        record
                                        {preview.total_sea_service_records === 1
                                            ? ''
                                            : 's'}{' '}
                                        generated from{' '}
                                        {isSingle
                                            ? 'this assignment'
                                            : 'these assignments'}
                                    </span>
                                </div>
                            ) : null}

                            {preview.total_training_records > 0 ? (
                                <div className="flex items-center justify-between text-muted-foreground">
                                    <span>Training</span>
                                    <span className="font-medium text-foreground">
                                        {preview.total_training_records} record
                                        {preview.total_training_records === 1
                                            ? ''
                                            : 's'}{' '}
                                        generated from{' '}
                                        {isSingle
                                            ? 'this assignment'
                                            : 'these assignments'}
                                    </span>
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2.5 border-t border-border/50 pt-2.5">
                            {preview.total_sea_service_records > 0 ? (
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="delete_sea_service"
                                        checked={form.data.delete_sea_service}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'delete_sea_service',
                                                Boolean(checked),
                                            )
                                        }
                                        disabled={!canDeleteSeaService}
                                    />
                                    <div className="grid gap-1 leading-none">
                                        <Label
                                            htmlFor="delete_sea_service"
                                            className="cursor-pointer text-xs font-medium text-foreground"
                                        >
                                            Delete generated Sea Service records
                                        </Label>
                                        {!canDeleteSeaService ? (
                                            <p className="text-[11px] text-destructive">
                                                You do not have permission to
                                                delete Sea Service records.
                                            </p>
                                        ) : !form.data.delete_sea_service ? (
                                            <p className="text-[11px] text-amber-600 dark:text-amber-400">
                                                This assignment has generated
                                                Sea Service records. To delete
                                                this erroneous assignment, also
                                                select &quot;Delete generated
                                                Sea Service&quot;, or use the
                                                appropriate correction/reversal
                                                workflow.
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                Explicitly linked generated Sea
                                                Service will be removed. Manual
                                                and imported history will remain
                                                untouched.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ) : null}

                            {hasTraining ? (
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="delete_training"
                                        checked={form.data.delete_training}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'delete_training',
                                                Boolean(checked),
                                            )
                                        }
                                        disabled={!canDeleteTraining}
                                    />
                                    <div className="grid gap-1 leading-none">
                                        <Label
                                            htmlFor="delete_training"
                                            className="cursor-pointer text-xs font-medium text-foreground"
                                        >
                                            Delete generated Training records
                                        </Label>
                                        {!canDeleteTraining ? (
                                            <p className="text-[11px] text-destructive">
                                                You do not have permission to
                                                delete Training records.
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                Optional: certificate files and
                                                training qualifications will be
                                                cleaned up. Uncheck to retain
                                                qualifications.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                <div className="space-y-2">
                    <Label
                        htmlFor="void_reason"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Reason for deletion{' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="void_reason"
                        value={form.data.void_reason}
                        onChange={(e) =>
                            form.setData('void_reason', e.target.value)
                        }
                        className="min-h-20 rounded-xl border-border bg-card text-xs"
                        placeholder="Wrong employee / duplicate assignment / assignment entered by mistake"
                        required
                        aria-required="true"
                    />
                    {form.errors.void_reason ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.void_reason}
                        </div>
                    ) : null}
                    {bagErrors.void ? (
                        <div className="text-xs font-medium text-destructive">
                            {bagErrors.void}
                        </div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Keep assignment{isSingle ? '' : 's'}
                    </AlertDialogCancel>
                    <Button
                        variant="destructive"
                        className="rounded-xl"
                        onClick={submit}
                        disabled={isSubmitDisabled}
                    >
                        {isSingle
                            ? 'Delete Assignment'
                            : `Delete ${targets.length} Assignments`}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
