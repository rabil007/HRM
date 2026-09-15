import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { BulkAddCommonDetails } from '@/features/organization/crew/bulk-add/bulk-add-common-details';
import { BulkAddCrewRows } from '@/features/organization/crew/bulk-add/bulk-add-crew-rows';
import type { BulkAddCrewRowState } from '@/features/organization/crew/bulk-add/bulk-add-crew-rows';
import {
    bulkFieldError,
    bulkRowIsBlocked,
} from '@/features/organization/crew/lib/bulk-row-status';
import type {
    BulkAddCrewFormData,
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { CREW_DIRECT_START_STAGES } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { dashboard } from '@/routes';
import {
    bulkStore,
    index as crewAssignmentsIndex,
} from '@/routes/organization/crew-assignments';

let nextRowKey = 1;

function newCrewRow(): BulkAddCrewRowState {
    nextRowKey += 1;

    return {
        key: `crew-row-${nextRowKey}`,
        employee_id: null,
        rank_id: null,
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

export function BulkAddCrewForm({
    form_options,
    can,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [rowKeys, setRowKeys] = useState<string[]>(['crew-row-1']);
    const form = useForm<BulkAddCrewFormData>({
        client_id: null,
        vessel_id: null,
        planned_join_at: '',
        current_stage: 'p1',
        remarks: '',
        crew: [{ employee_id: null, rank_id: null }],
    });

    const rows: BulkAddCrewRowState[] = form.data.crew.map((row, index) => ({
        ...row,
        key: rowKeys[index] ?? `crew-row-fallback-${index}`,
    }));

    const selectedRows = form.data.crew.filter(
        (row) => row.employee_id != null,
    );
    const blockedCount = selectedRows.filter((row) =>
        bulkRowIsBlocked(lookupStatus(form_options, row.employee_id)),
    ).length;
    const readyCount = selectedRows.length - blockedCount;
    const vesselName =
        form_options.vessels.find((vessel) => vessel.id === form.data.vessel_id)
            ?.name ?? 'Not selected';
    const stageLabel =
        CREW_DIRECT_START_STAGES.find(
            (stage) => stage.value === form.data.current_stage,
        )?.label ?? 'P1 · Travel In';
    const readyLabel =
        readyCount === 1
            ? '1 crew member ready'
            : `${readyCount} crew members ready`;
    const blockedLabel =
        blockedCount === 1
            ? '1 crew member cannot be started. Resolve the highlighted rows before continuing.'
            : `${blockedCount} crew members cannot be started. Resolve the highlighted rows before continuing.`;
    const submitLabel =
        readyCount === 1
            ? 'Start 1 Assignment'
            : `Start ${readyCount} Assignments`;
    const formErrors = form.errors as Record<string, string | undefined>;
    const backHref = can.view ? crewAssignmentsIndex.url() : dashboard.url();
    const backLabel = can.view
        ? 'Back to Crew Assignments'
        : 'Back to Dashboard';

    const submit = (event: React.FormEvent): void => {
        event.preventDefault();

        if (!can.start || blockedCount > 0) {
            return;
        }

        form.transform((data) => ({
            client_id: data.client_id,
            vessel_id: data.vessel_id,
            planned_join_at: data.planned_join_at,
            current_stage: data.current_stage,
            remarks: data.remarks,
            crew: data.crew
                .filter((row) => row.employee_id != null)
                .map((row) => ({
                    employee_id: row.employee_id,
                    rank_id: row.rank_id,
                })),
        }));

        form.post(bulkStore.url(), {
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Crew Assignments"
                title="Bulk Add Crew"
                description="Start multiple crew assignments using common mobilisation details."
                backHref={backHref}
                backLabel={backLabel}
            />

            <div className="mx-auto max-w-6xl space-y-6">
                <Card className="border-border/80 dark:border-white/10">
                    <CardContent className="p-6 md:p-8">
                        <form onSubmit={submit} className="space-y-10">
                            <BulkAddCommonDetails
                                form={form}
                                formOptions={form_options}
                            />

                            <BulkAddCrewRows
                                rows={rows}
                                formOptions={form_options}
                                errors={formErrors}
                                onAddRow={() => {
                                    const next = newCrewRow();
                                    setRowKeys((keys) => [...keys, next.key]);
                                    form.setData('crew', [
                                        ...form.data.crew,
                                        {
                                            employee_id: next.employee_id,
                                            rank_id: next.rank_id,
                                        },
                                    ]);
                                }}
                                onRemoveRow={(index) => {
                                    setRowKeys((keys) =>
                                        keys.filter((_, i) => i !== index),
                                    );
                                    form.setData(
                                        'crew',
                                        form.data.crew.filter(
                                            (_, i) => i !== index,
                                        ),
                                    );
                                }}
                                onChangeRow={(index, row) => {
                                    form.setData(
                                        'crew',
                                        form.data.crew.map((item, i) =>
                                            i === index ? row : item,
                                        ),
                                    );
                                }}
                            />

                            <div className="space-y-4 border-t border-border/60 pt-6">
                                <div className="rounded-xl border border-border/60 bg-muted/15 p-4 text-sm">
                                    <p className="font-semibold">
                                        {readyLabel}
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        Vessel: {vesselName}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Stage: {stageLabel}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Expected Join:{' '}
                                        {form.data.planned_join_at
                                            ? formatDisplayDate(
                                                  form.data.planned_join_at,
                                              )
                                            : 'Not set'}
                                    </p>
                                </div>

                                {blockedCount > 0 ? (
                                    <p className="text-sm font-medium text-destructive">
                                        {blockedLabel}
                                    </p>
                                ) : null}

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            !can.start ||
                                            readyCount < 1 ||
                                            blockedCount > 0
                                        }
                                        className="h-11 rounded-xl px-6"
                                    >
                                        {form.processing ? (
                                            <Spinner className="mr-2" />
                                        ) : null}
                                        {submitLabel}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 rounded-xl px-6"
                                        onClick={() => router.visit(backHref)}
                                    >
                                        Cancel
                                    </Button>
                                </div>

                                <InputError
                                    message={
                                        bulkFieldError(formErrors, 'error') ??
                                        formErrors.error
                                    }
                                    className="w-full"
                                />
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </Main>
    );
}
