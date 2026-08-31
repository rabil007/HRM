import { router, useForm } from '@inertiajs/react';
import {
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
                            <Plus className="mr-2 h-4 w-4" />
                            New approval flow
                        </Button>
                    ) : null
                }
            />

            <OrganizationDataTable minWidth="min-w-[960px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Name</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead>Stages</DataTableHead>
                        <DataTableHead>Routing summary</DataTableHead>
                        <DataTableHead>Updated</DataTableHead>
                        <DataTableHead className="w-[72px] text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {presets.length === 0 ? (
                        <TableRow className={dataTableBodyRowClass(false)}>
                            <TableCell
                                colSpan={6}
                                className={`${dataTableCellClass()} py-10 text-center text-sm text-muted-foreground`}
                            >
                                No approval flows configured yet.
                            </TableCell>
                        </TableRow>
                    ) : (
                        presets.map((preset) => (
                            <TableRow
                                key={preset.id}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div>
                                        <p className="font-medium">
                                            {preset.name}
                                        </p>
                                        {preset.description ? (
                                            <p className="text-xs text-muted-foreground">
                                                {preset.description}
                                            </p>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant={
                                            preset.status === 'active'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {preset.status_label}
                                    </Badge>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {preset.stage_count}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} max-w-md text-sm text-muted-foreground`}
                                >
                                    {preset.routing_summary}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatUpdatedAt(preset.updated_at)}
                                </TableCell>
                                <TableCell
                                    className={`${dataTableCellClass()} text-right`}
                                >
                                    {can.update || can.delete ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                >
                                                    <MoreHorizontal className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {can.update ? (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            openEdit(preset)
                                                        }
                                                    >
                                                        <Pencil className="mr-2 h-4 w-4" />
                                                        Edit
                                                    </DropdownMenuItem>
                                                ) : null}
                                                {can.update ? (
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            toggleStatus(preset)
                                                        }
                                                    >
                                                        {preset.status ===
                                                        'active' ? (
                                                            <>
                                                                <PowerOff className="mr-2 h-4 w-4" />
                                                                Deactivate
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Power className="mr-2 h-4 w-4" />
                                                                Activate
                                                            </>
                                                        )}
                                                    </DropdownMenuItem>
                                                ) : null}
                                                {can.delete ? (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            className="text-destructive focus:text-destructive"
                                                            onClick={() =>
                                                                setDeletePreset(
                                                                    preset,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : null}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>

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
