import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Download,
    Eye,
    FileSpreadsheet,
    Info,
    Loader2,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent, MouseEvent } from 'react';
import { show as vesselShow } from '@/actions/App/Http/Controllers/Settings/MasterData/VesselController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import {
    firstValidationError,
    hasFlashSuccess,
} from '@/lib/first-validation-error';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type VesselRow = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type?: { id: number; name: string } | null;
    grt: string | number | null;
    bhp: number | null;
    official_no: string | null;
    call_sign: string | null;
    imo_no: string | null;
    certificate_original_filename: string | null;
    certificate_url: string | null;
    is_active: boolean;
};

type VesselTypeOption = {
    id: number;
    name: string;
};

export default function Vessels({
    vessels,
    vessel_types,
    pagination,
    search = '',
}: {
    vessels: VesselRow[];
    vessel_types: VesselTypeOption[];
    pagination: PaginationMeta;
    search?: string;
}) {
    const can = useSettingsMasterDataCan('vessels');

    const list = useServerPaginationFilters({
        url: '/settings/master-data/vessels',
        search,
        filters: {},
        pagination,
    });

    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<VesselRow | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const certificateInputRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        name: '',
        vessel_type_id: '' as number | '',
        grt: '',
        bhp: '',
        official_no: '',
        call_sign: '',
        imo_no: '',
        certificate: null as File | null,
        is_active: true,
    });

    const rows = vessels;

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            vessel_type_id: '',
            grt: '',
            bhp: '',
            official_no: '',
            call_sign: '',
            imo_no: '',
            certificate: null,
            is_active: true,
        });

        if (certificateInputRef.current) {
            certificateInputRef.current.value = '';
        }

        setSheetOpen(true);
    };

    const openEdit = (row: VesselRow) => {
        setCurrent(row);
        form.reset();
        form.clearErrors();
        form.setData({
            name: row.name,
            vessel_type_id: row.vessel_type_id,
            grt:
                row.grt !== null && row.grt !== undefined
                    ? String(row.grt)
                    : '',
            bhp:
                row.bhp !== null && row.bhp !== undefined
                    ? String(row.bhp)
                    : '',
            official_no: row.official_no ?? '',
            call_sign: row.call_sign ?? '',
            imo_no: row.imo_no ?? '',
            certificate: null,
            is_active: row.is_active,
        });

        if (certificateInputRef.current) {
            certificateInputRef.current.value = '';
        }

        setSheetOpen(true);
    };

    const submit = () => {
        const hasCertificate = form.data.certificate instanceof File;

        const payload = {
            name: form.data.name,
            vessel_type_id: form.data.vessel_type_id,
            grt: form.data.grt.trim() === '' ? null : Number(form.data.grt),
            bhp:
                form.data.bhp.trim() === ''
                    ? null
                    : Number.parseInt(form.data.bhp, 10),
            official_no:
                form.data.official_no.trim() === ''
                    ? null
                    : form.data.official_no.trim(),
            call_sign:
                form.data.call_sign.trim() === ''
                    ? null
                    : form.data.call_sign.trim(),
            imo_no:
                form.data.imo_no.trim() === '' ? null : form.data.imo_no.trim(),
            is_active: form.data.is_active,
            ...(hasCertificate ? { certificate: form.data.certificate } : {}),
        };

        if (current) {
            form.transform(() => payload);
            form.put(`/settings/master-data/vessels/${current.id}`, {
                preserveScroll: true,
                forceFormData: hasCertificate,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.transform(() => payload);
        form.post('/settings/master-data/vessels', {
            preserveScroll: true,
            forceFormData: hasCertificate,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (row: VesselRow) => {
        setCurrent(row);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/vessels/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (row: VesselRow) => {
        router.put(
            `/settings/master-data/vessels/${row.id}`,
            {
                name: row.name,
                vessel_type_id: row.vessel_type_id,
                grt: row.grt,
                bhp: row.bhp,
                official_no: row.official_no,
                call_sign: row.call_sign,
                imo_no: row.imo_no,
                is_active: !row.is_active,
            },
            { preserveScroll: true },
        );
    };

    const openImport = () => {
        setImportFile(null);
        setImportMessage(null);
        setImportDragActive(false);
        setImportOpen(true);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const isCsvLike = (file: File): boolean =>
        file.type === 'text/csv' ||
        file.type === 'application/vnd.ms-excel' ||
        file.type === 'text/plain' ||
        file.name.toLowerCase().endsWith('.csv');

    const pickImportFile = (file: File | undefined | null) => {
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

    const clearImportFile = () => {
        setImportFile(null);
        setImportMessage(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const onImportDrag = (event: DragEvent) => {
        event.preventDefault();
        event.stopPropagation();
    };

    const onImportDrop = (event: DragEvent) => {
        event.preventDefault();
        event.stopPropagation();
        setImportDragActive(false);
        pickImportFile(event.dataTransfer.files?.[0] ?? null);
    };

    const onImportDragLeave = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        event.stopPropagation();
        const next = event.relatedTarget as Node | null;

        if (!event.currentTarget.contains(next)) {
            setImportDragActive(false);
        }
    };

    const runImport = () => {
        if (!importFile) {
            return;
        }

        setImportMessage(null);
        setImportProcessing(true);
        router.post(
            '/settings/master-data/vessels/import',
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

    return (
        <>
            <Head title="Vessels" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Vessels"
                    description="Manage vessels, types, GRT, BHP, and identification used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={list.searchInput}
                            onChange={(e) =>
                                list.onSearchChange(e.target.value)
                            }
                            placeholder="Search vessels..."
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={openImport}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Import CSV
                        </Button>
                        {can.create ? (
                            <Button onClick={openCreate}>Add vessel</Button>
                        ) : null}
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1280px] border-collapse text-sm">
                            <thead>
                                <tr className="bg-muted/30 text-left text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                                    <th className="px-4 py-3">Name</th>
                                    <th className="px-4 py-3">Vessel type</th>
                                    <th className="px-4 py-3">GRT</th>
                                    <th className="px-4 py-3">BHP</th>
                                    <th className="px-4 py-3">Official No</th>
                                    <th className="px-4 py-3">Call Sign</th>
                                    <th className="px-4 py-3">IMO No</th>
                                    <th className="px-4 py-3">Certificate</th>
                                    <th className="px-4 py-3">Active</th>
                                    <th className="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((v) => (
                                    <tr
                                        key={v.id}
                                        className="cursor-pointer border-t border-border/60 whitespace-nowrap hover:bg-muted/30"
                                        onClick={() =>
                                            router.visit(vesselShow.url(v.id))
                                        }
                                    >
                                        <td className="max-w-[180px] truncate px-4 py-3">
                                            <a
                                                href={vesselShow.url(v.id)}
                                                className="font-medium text-primary underline-offset-2 hover:underline"
                                                onClick={(
                                                    event: MouseEvent<HTMLAnchorElement>,
                                                ) => event.stopPropagation()}
                                            >
                                                {v.name}
                                            </a>
                                        </td>
                                        <td className="max-w-[140px] truncate px-4 py-3 text-muted-foreground">
                                            {v.vessel_type?.name ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {v.grt ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {v.bhp ?? '—'}
                                        </td>
                                        <td className="max-w-[120px] truncate px-4 py-3">
                                            {v.official_no ?? '—'}
                                        </td>
                                        <td className="max-w-[100px] truncate px-4 py-3">
                                            {v.call_sign ?? '—'}
                                        </td>
                                        <td className="max-w-[100px] truncate px-4 py-3">
                                            {v.imo_no ?? '—'}
                                        </td>
                                        <td
                                            className="max-w-[160px] truncate px-4 py-3"
                                            onClick={(
                                                event: MouseEvent<HTMLTableCellElement>,
                                            ) => event.stopPropagation()}
                                        >
                                            {v.certificate_url ? (
                                                <a
                                                    href={v.certificate_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-primary underline-offset-2 hover:underline"
                                                >
                                                    {v.certificate_original_filename ??
                                                        'View'}
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td
                                            className="px-4 py-3"
                                            onClick={(
                                                event: MouseEvent<HTMLTableCellElement>,
                                            ) => event.stopPropagation()}
                                        >
                                            <Switch
                                                disabled={!can.update}
                                                checked={v.is_active}
                                                onCheckedChange={() =>
                                                    toggleActive(v)
                                                }
                                            />
                                        </td>
                                        <td
                                            className="px-4 py-3"
                                            onClick={(
                                                event: MouseEvent<HTMLTableCellElement>,
                                            ) => event.stopPropagation()}
                                        >
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={vesselShow.url(
                                                            v.id,
                                                        )}
                                                    >
                                                        <Eye className="mr-1.5 size-3.5" />
                                                        View
                                                    </a>
                                                </Button>
                                                {can.update ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            openEdit(v)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                ) : null}
                                                {can.delete ? (
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        onClick={() =>
                                                            requestDelete(v)
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {rows.length === 0 ? (
                            <div className="px-4 py-10 text-sm text-muted-foreground">
                                No vessels found.
                            </div>
                        ) : null}
                    </div>
                </div>

                <Pagination {...list.paginationProps} label="vessels" />
            </div>

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
                            <a href="/settings/master-data/vessels/import/template">
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

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
                >
                    <SheetHeader className="border-b border-border/60 p-8 pb-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            {current ? 'Edit vessel' : 'New vessel'}
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                            Identification details and certificate are optional.
                            GRT and BHP are shown on sea service records.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-5 overflow-y-auto p-8">
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="ADNOC 951"
                            />
                            {form.errors.name ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="vessel_type_id">Vessel type</Label>
                            <AppSelect
                                value={
                                    form.data.vessel_type_id === ''
                                        ? ''
                                        : String(form.data.vessel_type_id)
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'vessel_type_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="dark"
                                placeholder="Select type"
                            >
                                <AppSelectItem value="">—</AppSelectItem>
                                {vessel_types.map((type) => (
                                    <AppSelectItem
                                        key={type.id}
                                        value={String(type.id)}
                                    >
                                        {type.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.vessel_type_id ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.vessel_type_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="grt">GRT</Label>
                                <Input
                                    id="grt"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.grt}
                                    onChange={(e) =>
                                        form.setData('grt', e.target.value)
                                    }
                                />
                                {form.errors.grt ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.grt}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="bhp">BHP</Label>
                                <Input
                                    id="bhp"
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={form.data.bhp}
                                    onChange={(e) =>
                                        form.setData('bhp', e.target.value)
                                    }
                                />
                                {form.errors.bhp ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.bhp}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="official_no">Official No</Label>
                            <Input
                                id="official_no"
                                value={form.data.official_no}
                                onChange={(e) =>
                                    form.setData('official_no', e.target.value)
                                }
                                placeholder="Official number"
                            />
                            {form.errors.official_no ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.official_no}
                                </div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="call_sign">Call Sign</Label>
                                <Input
                                    id="call_sign"
                                    value={form.data.call_sign}
                                    onChange={(e) =>
                                        form.setData(
                                            'call_sign',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Call sign"
                                />
                                {form.errors.call_sign ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.call_sign}
                                    </div>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="imo_no">IMO No</Label>
                                <Input
                                    id="imo_no"
                                    value={form.data.imo_no}
                                    onChange={(e) =>
                                        form.setData('imo_no', e.target.value)
                                    }
                                    placeholder="IMO number"
                                />
                                {form.errors.imo_no ? (
                                    <div className="text-xs text-destructive">
                                        {form.errors.imo_no}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="certificate">
                                Certificate of vessel
                            </Label>
                            <Input
                                ref={certificateInputRef}
                                id="certificate"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                onChange={(e) =>
                                    form.setData(
                                        'certificate',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {form.data.certificate ? (
                                <div className="text-xs text-muted-foreground">
                                    Selected: {form.data.certificate.name}
                                </div>
                            ) : current?.certificate_url ? (
                                <div className="text-xs text-muted-foreground">
                                    Current:{' '}
                                    <a
                                        href={current.certificate_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-primary underline-offset-2 hover:underline"
                                    >
                                        {current.certificate_original_filename ??
                                            'View certificate'}
                                    </a>
                                </div>
                            ) : (
                                <div className="text-xs text-muted-foreground">
                                    PDF, JPG, or PNG up to 5 MB.
                                </div>
                            )}
                            {form.errors.certificate ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.certificate}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold">
                                    Active
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Disable to hide from selections.
                                </div>
                            </div>
                            <Switch
                                disabled={!can.update}
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v)
                                }
                            />
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button
                            type="button"
                            variant="ghost"
                            className="flex-1"
                            onClick={() => setSheetOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="flex-1"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            {form.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete vessel</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current
                                ? `This will permanently delete “${current.name}”.`
                                : 'This will permanently delete this vessel.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
