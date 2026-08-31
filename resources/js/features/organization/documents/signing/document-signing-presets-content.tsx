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
import { SigningPresetFormDialog } from '@/features/organization/documents/signing/signing-preset-form-dialog';
import type {
    DocumentSigningPresetsIndexProps,
    SigningPresetSummary,
} from '@/features/organization/documents/signing/types';
import documentRoutes from '@/routes/organization/documents';
import {
    activate as activatePreset,
    destroy as destroyPreset,
    deactivate as deactivatePreset,
} from '@/routes/organization/documents/signing-presets';

export function DocumentSigningPresetsContent({
    presets,
    can,
    form_options,
}: DocumentSigningPresetsIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingPreset, setEditingPreset] =
        useState<SigningPresetSummary | null>(null);
    const [deletePreset, setDeletePreset] =
        useState<SigningPresetSummary | null>(null);

    const deleteForm = useForm({});

    function openCreate() {
        setEditingPreset(null);
        setDialogOpen(true);
    }

    function openEdit(preset: SigningPresetSummary) {
        setEditingPreset(preset);
        setDialogOpen(true);
    }

    return (
        <>
            <Main>
                <DetailsHeader
                    title="Signing presets"
                    description="Reusable sequential signing chains for employee documents."
                    backHref={documentRoutes.requests.url()}
                    backLabel="Requests"
                    actions={
                        can.create ? (
                            <Button type="button" onClick={openCreate}>
                                <Plus className="mr-2 h-4 w-4" />
                                New preset
                            </Button>
                        ) : null
                    }
                />

                <OrganizationDataTable minWidth="min-w-[960px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Name</DataTableHead>
                            <DataTableHead>Sequence</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="w-[72px] text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {presets.length === 0 ? (
                            <TableRow className={dataTableBodyRowClass(false)}>
                                <TableCell
                                    colSpan={4}
                                    className={`${dataTableCellClass()} py-10 text-center text-sm text-muted-foreground`}
                                >
                                    No signing presets yet.
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
                                        {preset.name}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {preset.routing_summary}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge
                                            variant={
                                                preset.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {preset.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
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
                                                            router.post(
                                                                preset.is_active
                                                                    ? deactivatePreset.url(
                                                                          preset.id,
                                                                      )
                                                                    : activatePreset.url(
                                                                          preset.id,
                                                                      ),
                                                            )
                                                        }
                                                    >
                                                        {preset.is_active ? (
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
                                                            className="text-destructive"
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
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </OrganizationDataTable>
            </Main>

            <SigningPresetFormDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                preset={editingPreset}
                formOptions={form_options}
            />

            <ConfirmDeleteDialog
                open={deletePreset !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletePreset(null);
                    }
                }}
                title="Delete signing preset?"
                description="Used presets cannot be deleted. Deactivate them instead."
                onConfirm={() => {
                    if (!deletePreset) {
                        return;
                    }

                    deleteForm.delete(destroyPreset.url(deletePreset.id), {
                        preserveScroll: true,
                        onSuccess: () => setDeletePreset(null),
                    });
                }}
            />
        </>
    );
}
