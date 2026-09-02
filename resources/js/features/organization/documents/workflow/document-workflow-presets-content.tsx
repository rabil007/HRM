import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { TableRowActions } from '@/components/table-row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    DocumentWorkflowPresetsIndexProps,
    WorkflowPresetSummary,
} from '@/features/organization/documents/workflow/types';
import { WorkflowPresetFormDialog } from '@/features/organization/documents/workflow/workflow-preset-form-dialog';
import { WorkflowPresetRoutingSteps } from '@/features/organization/documents/workflow/workflow-preset-routing-steps';
import documentRoutes from '@/routes/organization/documents';
import {
    activate as activatePreset,
    destroy as destroyPreset,
    deactivate as deactivatePreset,
} from '@/routes/organization/documents/workflow-presets';

function formatUpdatedAt(value: string | null): string {
    if (!value) {
        return '—';
    }

    try {
        return new Date(value).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    } catch {
        return '—';
    }
}

export function DocumentWorkflowPresetsContent({
    presets,
    can,
    form_options,
}: DocumentWorkflowPresetsIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingPreset, setEditingPreset] =
        useState<WorkflowPresetSummary | null>(null);
    const [deletePreset, setDeletePreset] =
        useState<WorkflowPresetSummary | null>(null);

    const deleteForm = useForm({});

    function openCreate() {
        setEditingPreset(null);
        setDialogOpen(true);
    }

    function openEdit(preset: WorkflowPresetSummary) {
        setEditingPreset(preset);
        setDialogOpen(true);
    }

    function confirmDelete() {
        if (!deletePreset) {
            return;
        }

        deleteForm.delete(destroyPreset.url(deletePreset.id), {
            preserveScroll: true,
            onSuccess: () => setDeletePreset(null),
        });
    }

    function toggleStatus(preset: WorkflowPresetSummary) {
        const route =
            preset.status === 'active'
                ? deactivatePreset.url(preset.id)
                : activatePreset.url(preset.id);

        router.post(route, {}, { preserveScroll: true });
    }

    return (
        <Main>
            <DetailsHeader
                title="Approval Flows"
                description="Reusable multi-stage review and approval workflows for generated documents."
                backHref={documentRoutes.requests.url()}
                backLabel="Requests"
                actions={
                    can.create ? (
                        <Button type="button" onClick={openCreate}>
                            <Plus className="mr-2 size-4" />
                            New approval flow
                        </Button>
                    ) : null
                }
            />

            {presets.length === 0 ? (
                <EmptyState
                    title="No approval flows yet"
                    description="Create a reusable review and approval sequence, then attach it when you design a template."
                    action={
                        can.create ? (
                            <Button type="button" onClick={openCreate}>
                                <Plus className="mr-2 size-4" />
                                New approval flow
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <OrganizationDataTable
                    minWidth="min-w-[880px]"
                    tableClassName="table-fixed"
                >
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="w-[22%]">
                                Name
                            </DataTableHead>
                            <DataTableHead className="w-[7.5rem]">
                                Status
                            </DataTableHead>
                            <DataTableHead className="w-[5.5rem]">
                                Stages
                            </DataTableHead>
                            <DataTableHead>Routing</DataTableHead>
                            <DataTableHead className="w-[7.5rem]">
                                Updated
                            </DataTableHead>
                            <DataTableHead className="w-[8.5rem] text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {presets.map((preset) => (
                            <TableRow
                                key={preset.id}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {preset.name}
                                        </p>
                                        {preset.description ? (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {preset.description}
                                            </p>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant={
                                            preset.status === 'active'
                                                ? 'success'
                                                : 'secondary'
                                        }
                                    >
                                        {preset.status_label}
                                    </Badge>
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} tabular-nums`}
                                >
                                    {preset.stage_count}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} min-w-0`}
                                >
                                    <WorkflowPresetRoutingSteps
                                        stages={preset.stages}
                                        summary={preset.routing_summary}
                                    />
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} whitespace-nowrap text-muted-foreground`}
                                >
                                    {formatUpdatedAt(preset.updated_at)}
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <TableRowActions
                                        actions={[
                                            {
                                                label: `Edit ${preset.name}`,
                                                icon: Pencil,
                                                onClick: () =>
                                                    openEdit(preset),
                                                hidden: !can.update,
                                            },
                                            {
                                                label:
                                                    preset.status === 'active'
                                                        ? `Deactivate ${preset.name}`
                                                        : `Activate ${preset.name}`,
                                                icon:
                                                    preset.status === 'active'
                                                        ? PowerOff
                                                        : Power,
                                                onClick: () =>
                                                    toggleStatus(preset),
                                                hidden: !can.update,
                                            },
                                            {
                                                label: `Delete ${preset.name}`,
                                                icon: Trash2,
                                                variant: 'danger',
                                                onClick: () =>
                                                    setDeletePreset(preset),
                                                hidden: !can.delete,
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            {can.create || can.update ? (
                <WorkflowPresetFormDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    preset={editingPreset}
                    formOptions={form_options}
                />
            ) : null}

            {can.delete ? (
                <ConfirmDeleteDialog
                    open={deletePreset !== null}
                    onOpenChange={(open) => !open && setDeletePreset(null)}
                    title="Delete workflow preset"
                    description={
                        deletePreset ? (
                            <>
                                Are you sure you want to delete{' '}
                                <span className="font-semibold text-foreground">
                                    {deletePreset.name}
                                </span>
                                ? This action cannot be undone.
                            </>
                        ) : (
                            ''
                        )
                    }
                    confirmText={
                        deleteForm.processing ? 'Deleting…' : 'Delete preset'
                    }
                    onConfirm={confirmDelete}
                />
            ) : null}
        </Main>
    );
}
