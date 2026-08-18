import { router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Filter,
    Info,
    Loader2,
    Ship,
    Upload,
    Users,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent } from 'react';
import {
    destroy as destroyVessel,
    importMethod as importVessels,
    importTemplate as importVesselTemplate,
    index as vesselsIndex,
    show as vesselShow,
    store as storeVessel,
    update as updateVessel,
} from '@/actions/App/Http/Controllers/Organization/VesselController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import {
    firstValidationError,
    hasFlashSuccess,
} from '@/lib/first-validation-error';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { VesselDeleteDialog } from './components/vessel-delete-dialog';
import { VesselFormSheet } from './components/vessel-form-sheet';
import type {
    VesselFormData,
    VesselPageCan,
    VesselRow,
    VesselTypeOption,
} from './types';

function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    accent,
}: {
    label: string;
    value: string | number;
    hint: string;
    icon: React.ComponentType<{ className?: string }>;
    accent: string;
}) {
    return (
        <div className="group relative overflow-hidden rounded-2xl border glass-card border-border/60 bg-card/80 p-5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:shadow-md dark:hover:border-white/10">
            <div
                className={cn(
                    'pointer-events-none absolute -top-4 -right-4 size-24 rounded-full opacity-20 blur-2xl transition-opacity group-hover:opacity-30',
                    accent,
                )}
            />
            <div className="relative flex items-start justify-between gap-4">
                <div className="space-y-2">
                    <p className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                        {label}
                    </p>
                    <p className="text-3xl font-extrabold tracking-tight tabular-nums">
                        {value}
                    </p>
                    <p className="text-xs font-medium text-muted-foreground/75">
                        {hint}
                    </p>
                </div>
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/40 dark:border-white/8 dark:bg-white/6">
                    <Icon className="size-5 text-muted-foreground" />
                </div>
            </div>
        </div>
    );
}

function VesselAvatar({ name }: { name: string }) {
    return (
        <div className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary dark:border-primary/15 dark:bg-primary/[0.08]">
            <Ship className="size-4" />
        </div>
    );
}

function emptyFormData(): VesselFormData {
    return {
        name: '',
        vessel_type_id: '',
        grt: '',
        bhp: '',
        official_no: '',
        call_sign: '',
        imo_no: '',
        certificate: null,
        is_active: true,
    };
}

function fromVesselRow(row: VesselRow): VesselFormData {
    return {
        name: row.name,
        vessel_type_id: row.vessel_type_id,
        grt: row.grt !== null && row.grt !== undefined ? String(row.grt) : '',
        bhp: row.bhp !== null && row.bhp !== undefined ? String(row.bhp) : '',
        official_no: row.official_no ?? '',
        call_sign: row.call_sign ?? '',
        imo_no: row.imo_no ?? '',
        certificate: null,
        is_active: row.is_active,
    };
}

function toPayload(
    data: VesselFormData,
    hasCertificate: boolean,
): Record<string, unknown> {
    return {
        name: data.name,
        vessel_type_id: data.vessel_type_id,
        grt: data.grt.trim() === '' ? null : Number(data.grt),
        bhp: data.bhp.trim() === '' ? null : Number.parseInt(data.bhp, 10),
        official_no:
            data.official_no.trim() === '' ? null : data.official_no.trim(),
        call_sign: data.call_sign.trim() === '' ? null : data.call_sign.trim(),
        imo_no: data.imo_no.trim() === '' ? null : data.imo_no.trim(),
        is_active: data.is_active,
        ...(hasCertificate ? { certificate: data.certificate } : {}),
    };
}

export function VesselsContent({
    vessels,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    vessel_types,
    can,
}: {
    vessels: VesselRow[];
    pagination: PaginationMeta;
    search: string;
    filters: { vessel_type_id: number | null };
    vessel_types: VesselTypeOption[];
    can: VesselPageCan;
}) {
    const list = useServerPaginationFilters({
        url: vesselsIndex.url(),
        search: initialSearch,
        filters: {
            vessel_type_id: initialFilters.vessel_type_id
                ? String(initialFilters.vessel_type_id)
                : '',
        },
        pagination,
    });

    const listBackQuery = useMemo(() => {
        const query: Record<string, string> = {};

        if (initialSearch.trim() !== '') {
            query.search = initialSearch;
        }

        if (initialFilters.vessel_type_id) {
            query.vessel_type_id = String(initialFilters.vessel_type_id);
        }

        if (pagination.current_page > 1) {
            query.page = String(pagination.current_page);
        }

        if (pagination.per_page) {
            query.per_page = String(pagination.per_page);
        }

        return query;
    }, [
        initialFilters.vessel_type_id,
        initialSearch,
        pagination.current_page,
        pagination.per_page,
    ]);

    const openShow = (vesselId: number): void => {
        router.visit(
            vesselShow.url(
                vesselId,
                Object.keys(listBackQuery).length > 0
                    ? { query: listBackQuery }
                    : undefined,
            ),
        );
    };

    const form = useForm<VesselFormData>(emptyFormData());

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editingVessel, setEditingVessel] = useState<VesselRow | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deletingVessel, setDeletingVessel] = useState<VesselRow | null>(
        null,
    );
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const openCreate = (): void => {
        setEditingVessel(null);
        form.reset();
        form.clearErrors();
        form.setData(emptyFormData());
        setSheetOpen(true);
    };

    const openEdit = (row: VesselRow): void => {
        setEditingVessel(row);
        form.clearErrors();
        form.setData(fromVesselRow(row));
        setSheetOpen(true);
    };

    const closeSheet = (): void => {
        setSheetOpen(false);
        setEditingVessel(null);
    };

    const submit = (): void => {
        const hasCertificate = form.data.certificate instanceof File;

        form.transform(() => toPayload(form.data, hasCertificate));

        if (editingVessel) {
            form.put(updateVessel.url(editingVessel.id), {
                preserveScroll: true,
                forceFormData: hasCertificate,
                onSuccess: () => closeSheet(),
            });
        } else {
            form.post(storeVessel.url(), {
                preserveScroll: true,
                forceFormData: hasCertificate,
                onSuccess: () => closeSheet(),
            });
        }
    };

    const requestDelete = (row: VesselRow): void => {
        setDeletingVessel(row);
        setDeleteOpen(true);
    };

    const confirmDelete = (): void => {
        if (!deletingVessel) {
            return;
        }

        router.delete(destroyVessel.url(deletingVessel.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setDeletingVessel(null);
            },
        });
    };

    const isCsvLike = (file: File): boolean =>
        file.type === 'text/csv' ||
        file.type === 'application/vnd.ms-excel' ||
        file.type === 'text/plain' ||
        file.name.toLowerCase().endsWith('.csv');

    const pickImportFile = (file: File | undefined | null): void => {
        if (!file) {
            return;
        }

        if (!isCsvLike(file)) {
            setImportMessage('Please choose a .csv file.');

            return;
        }

        setImportFile(file);
        setImportMessage(null);
    };

    const clearImportFile = (): void => {
        setImportFile(null);
        setImportMessage(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const onImportDrag = (event: DragEvent): void => {
        event.preventDefault();
        event.stopPropagation();
    };

    const onImportDrop = (event: DragEvent): void => {
        event.preventDefault();
        event.stopPropagation();
        setImportDragActive(false);
        pickImportFile(event.dataTransfer.files?.[0] ?? null);
    };

    const onImportDragLeave = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();
        event.stopPropagation();
        const next = event.relatedTarget as Node | null;

        if (!event.currentTarget.contains(next)) {
            setImportDragActive(false);
        }
    };

    const openImport = (): void => {
        setImportFile(null);
        setImportMessage(null);
        setImportDragActive(false);
        setImportOpen(true);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const runImport = (): void => {
        if (!importFile) {
            return;
        }

        setImportMessage(null);
        setImportProcessing(true);
        router.post(
            importVessels.url(),
            { file: importFile },
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => setImportProcessing(false),
                onSuccess: (page) => {
                    if (hasFlashSuccess(page)) {
                        setImportOpen(false);
                        setImportFile(null);
                        setImportMessage(null);

                        if (fileInputRef.current) {
                            fileInputRef.current.value = '';
                        }
                    }
                },
                onError: (errs) =>
                    setImportMessage(
                        firstValidationError(
                            errs as Record<string, string | string[]>,
                            'file',
                            'Import failed.',
                        ),
                    ),
            },
        );
    };

    const hasVesselTypeFilter = Boolean(initialFilters.vessel_type_id);
    const hasActiveFilters = hasVesselTypeFilter || initialSearch.trim() !== '';

    return (
        <Main>
            <PageHeader
                kicker="Crew Operations"
                title="Vessels"
                description="Manage company vessels, identification details, and manning requirements."
                right={
                    can.create ? (
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                type="button"
                                onClick={openImport}
                            >
                                <Upload className="mr-2 h-4 w-4" />
                                Import CSV
                            </Button>
                            <Button onClick={openCreate}>Add vessel</Button>
                        </div>
                    ) : null
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
                <StatCard
                    label="Total Fleet"
                    value={pagination.total}
                    hint="Vessels registered for this company"
                    icon={Ship}
                    accent="bg-primary"
                />
                <StatCard
                    label="Configured Manning"
                    value={`${vessels.filter((v) => v.ranks_configured > 0).length} / ${vessels.length}`}
                    hint="Vessels with crew requirements set (this page)"
                    icon={CheckCircle2}
                    accent="bg-emerald-500"
                />
                <StatCard
                    label="Required Headcount"
                    value={vessels.reduce(
                        (acc, v) => acc + (v.total_required || 0),
                        0,
                    )}
                    hint="Total headcount required (this page)"
                    icon={Users}
                    accent="bg-blue-500"
                />
            </div>

            <Card className="mb-6 border-border/60 bg-card/60 backdrop-blur-md dark:border-white/5 dark:bg-white/[0.02]">
                <CardContent className="p-5">
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <Filter className="h-4 w-4 text-muted-foreground/50" />
                        <span className="text-xs font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Filters
                        </span>
                        {hasActiveFilters ? (
                            <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                Active
                            </Badge>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-3 lg:flex-row">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search vessels…"
                            className="mb-0 min-w-0 flex-1"
                        />

                        <AppSelect
                            value={
                                initialFilters.vessel_type_id
                                    ? String(initialFilters.vessel_type_id)
                                    : ''
                            }
                            onValueChange={(vesselTypeId) =>
                                list.applyFilters({
                                    vessel_type_id: vesselTypeId,
                                })
                            }
                            placeholder="All vessel types"
                            variant="dark"
                            className="h-10 lg:w-64"
                        >
                            <AppSelectItem value="">
                                All vessel types
                            </AppSelectItem>
                            {vessel_types.map((vesselType) => (
                                <AppSelectItem
                                    key={vesselType.id}
                                    value={String(vesselType.id)}
                                >
                                    {vesselType.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>
                </CardContent>
            </Card>

            {vessels.length === 0 ? (
                <EmptyState
                    icon={
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Ship className="h-6 w-6 text-muted-foreground" />
                        </div>
                    }
                    title={
                        hasActiveFilters
                            ? 'No vessels match your filters.'
                            : 'No vessels yet.'
                    }
                    description={
                        hasActiveFilters
                            ? 'Try adjusting your search or vessel type filter.'
                            : 'Add your first vessel to get started.'
                    }
                    action={
                        can.create && !hasActiveFilters ? (
                            <Button onClick={openCreate}>Add vessel</Button>
                        ) : undefined
                    }
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[1100px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Vessel</DataTableHead>
                            <DataTableHead>Type</DataTableHead>
                            <DataTableHead>Identification</DataTableHead>
                            <DataTableHead>Manning</DataTableHead>
                            <DataTableHead>Required Crew</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                            <DataTableHead className="text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {vessels.map((vessel) => (
                            <TableRow
                                key={vessel.id}
                                className={cn(
                                    dataTableBodyRowClass(),
                                    'cursor-pointer',
                                )}
                                onClick={() => openShow(vessel.id)}
                            >
                                <TableCell
                                    className={dataTableCellPrimaryClass()}
                                >
                                    <div className="flex items-center gap-3">
                                        <VesselAvatar name={vessel.name} />
                                        <div className="min-w-0">
                                            <div className="truncate font-semibold">
                                                {vessel.name}
                                            </div>
                                            {vessel.call_sign ? (
                                                <div className="text-xs text-muted-foreground/70">
                                                    {vessel.call_sign}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.vessel_type?.name ??
                                    vessel.vessel_type_name ? (
                                        <Badge
                                            variant="outline"
                                            className="border-border/80 text-[10px] font-bold tracking-wider uppercase"
                                        >
                                            {vessel.vessel_type?.name ??
                                                vessel.vessel_type_name}
                                        </Badge>
                                    ) : (
                                        <span className="text-muted-foreground/50">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <div className="space-y-1">
                                        {vessel.imo_no ? (
                                            <div className="flex items-center gap-1.5 text-xs">
                                                <span className="inline-block w-10 shrink-0 text-[10px] font-bold tracking-wider text-muted-foreground/60 uppercase">
                                                    IMO
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {vessel.imo_no}
                                                </span>
                                            </div>
                                        ) : null}
                                        {vessel.official_no ? (
                                            <div className="flex items-center gap-1.5 text-xs">
                                                <span className="inline-block w-10 shrink-0 text-[10px] font-bold tracking-wider text-muted-foreground/60 uppercase">
                                                    Off.
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {vessel.official_no}
                                                </span>
                                            </div>
                                        ) : null}
                                        {!vessel.imo_no &&
                                        !vessel.official_no ? (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        ) : null}
                                    </div>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.ranks_configured === 0 ? (
                                        <Badge className="border-amber-500/20 bg-amber-500/10 text-[10px] font-bold tracking-wider text-amber-700 uppercase dark:text-amber-400">
                                            Not set
                                        </Badge>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-foreground">
                                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                                            {vessel.ranks_configured}{' '}
                                            {vessel.ranks_configured === 1
                                                ? 'rank'
                                                : 'ranks'}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {vessel.total_required > 0 ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-lg border border-blue-500/20 bg-blue-500/10 px-2.5 py-1 text-xs font-bold tabular-nums text-blue-700 dark:text-blue-400">
                                            <Users className="h-3.5 w-3.5" />
                                            {vessel.total_required}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground/50">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        className={
                                            vessel.is_active
                                                ? 'border-emerald-500/20 bg-emerald-500/15 text-[10px] font-bold tracking-wider text-emerald-700 uppercase dark:text-emerald-400'
                                                : 'border border-border/80 bg-muted/60 text-[10px] font-bold tracking-wider text-muted-foreground uppercase'
                                        }
                                    >
                                        {vessel.is_active
                                            ? 'Active'
                                            : 'Inactive'}
                                    </Badge>
                                </TableCell>
                                <TableCell
                                    className={dataTableActionsCellClass()}
                                >
                                    <ListTableCrudActions
                                        viewHref={vesselShow.url(
                                            vessel.id,
                                            Object.keys(listBackQuery).length >
                                                0
                                                ? { query: listBackQuery }
                                                : undefined,
                                        )}
                                        onEdit={
                                            can.update
                                                ? (event) => {
                                                      event.stopPropagation();
                                                      openEdit(vessel);
                                                  }
                                                : undefined
                                        }
                                        showEdit={can.update}
                                        onDelete={
                                            can.delete
                                                ? (event) => {
                                                      event.stopPropagation();
                                                      requestDelete(vessel);
                                                  }
                                                : undefined
                                        }
                                        showDelete={can.delete}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            <Pagination {...list.paginationProps} label="vessels" />

            <VesselFormSheet
                open={sheetOpen}
                onOpenChange={(open) => {
                    if (!open) {
                        closeSheet();
                    } else {
                        setSheetOpen(true);
                    }
                }}
                vessel={editingVessel}
                vesselTypes={vessel_types}
                form={form}
                onSubmit={submit}
            />

            <VesselDeleteDialog
                open={deleteOpen}
                onOpenChange={(open) => {
                    setDeleteOpen(open);

                    if (!open) {
                        setDeletingVessel(null);
                    }
                }}
                vessel={deletingVessel}
                onConfirm={confirmDelete}
            />

            <Dialog
                open={importOpen}
                onOpenChange={(open) => {
                    setImportOpen(open);

                    if (!open) {
                        setImportFile(null);
                        setImportMessage(null);
                        setImportDragActive(false);

                        if (fileInputRef.current) {
                            fileInputRef.current.value = '';
                        }
                    }
                }}
            >
                <DialogContent className="gap-0 overflow-hidden border-border p-0 sm:max-w-lg">
                    <DialogHeader className="space-y-0 border-b border-border px-6 py-5 text-left sm:text-left">
                        <div className="flex gap-4">
                            <div
                                className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-inner ring-1 ring-primary/15"
                                aria-hidden
                            >
                                <Upload className="size-5" />
                            </div>
                            <div className="min-w-0 space-y-1.5 pt-0.5">
                                <DialogTitle className="text-xl leading-tight">
                                    Import vessels
                                </DialogTitle>
                                <DialogDescription>
                                    Add or update vessels in bulk. Existing
                                    names are updated.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="space-y-5 px-6 py-5">
                        <Alert className="border-border/80 bg-muted/40">
                            <Info className="text-primary" aria-hidden />
                            <AlertDescription>
                                <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                                    <li>
                                        <span className="font-medium text-foreground">
                                            name
                                        </span>{' '}
                                        — required
                                    </li>
                                    <li>
                                        <span className="font-medium text-foreground">
                                            vessel_type
                                        </span>{' '}
                                        — required (must match an existing type)
                                    </li>
                                    <li>
                                        <span className="font-medium text-foreground">
                                            grt
                                        </span>
                                        ,{' '}
                                        <span className="font-medium text-foreground">
                                            bhp
                                        </span>{' '}
                                        — optional
                                    </li>
                                </ul>
                            </AlertDescription>
                        </Alert>

                        <Button
                            variant="secondary"
                            type="button"
                            className="w-full sm:w-auto"
                            asChild
                        >
                            <a href={importVesselTemplate.url()}>
                                <Download className="mr-2 size-4" />
                                Download CSV template
                            </a>
                        </Button>

                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
                            className="sr-only"
                            id="vessels-import-file"
                            onChange={(event) =>
                                pickImportFile(event.target.files?.[0])
                            }
                        />
                        <div
                            role="button"
                            tabIndex={0}
                            className={cn(
                                'rounded-xl border-2 border-dashed border-border bg-background/80 p-6 text-center',
                                importDragActive &&
                                    'border-primary bg-primary/6',
                                importFile &&
                                    'border-solid border-emerald-500/40 bg-emerald-500/7',
                            )}
                            onClick={() => fileInputRef.current?.click()}
                            onKeyDown={(
                                event: KeyboardEvent<HTMLDivElement>,
                            ) => {
                                if (
                                    event.key === 'Enter' ||
                                    event.key === ' '
                                ) {
                                    event.preventDefault();
                                    fileInputRef.current?.click();
                                }
                            }}
                            onDragEnter={(event: DragEvent) => {
                                onImportDrag(event);
                                setImportDragActive(true);
                            }}
                            onDragOver={onImportDrag}
                            onDragLeave={onImportDragLeave}
                            onDrop={onImportDrop}
                        >
                            {importFile ? (
                                <div className="flex items-center justify-center gap-3">
                                    <FileSpreadsheet className="size-5 text-emerald-600" />
                                    <span className="text-sm font-medium">
                                        {importFile.name}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            clearImportFile();
                                        }}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Drop your CSV here or click to browse
                                </p>
                            )}
                        </div>

                        {importMessage ? (
                            <Alert variant="destructive">
                                <AlertCircle aria-hidden />
                                <AlertDescription>
                                    {importMessage}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </div>

                    <DialogFooter className="gap-2 border-t border-border bg-muted/30 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={importProcessing}
                            onClick={() => setImportOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            disabled={!importFile || importProcessing}
                            onClick={runImport}
                        >
                            {importProcessing ? (
                                <>
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                    Importing…
                                </>
                            ) : (
                                'Import'
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Main>
    );
}
