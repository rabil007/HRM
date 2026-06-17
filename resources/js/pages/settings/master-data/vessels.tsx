import { Head, router, useForm } from '@inertiajs/react';
import { AlertCircle, Download, FileSpreadsheet, Info, Loader2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import Heading from '@/components/heading';
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
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { firstValidationError, hasFlashSuccess } from '@/lib/first-validation-error';
import { cn } from '@/lib/utils';

type VesselRow = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type?: { id: number; name: string } | null;
    grt: string | number | null;
    bhp: number | null;
    is_active: boolean;
};

type VesselTypeOption = {
    id: number;
    name: string;
};

export default function Vessels({
    vessels,
    vessel_types,
}: {
    vessels: VesselRow[];
    vessel_types: VesselTypeOption[];
}) {
    const can = useSettingsMasterDataCan('vessels');

    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<VesselRow | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        name: '',
        vessel_type_id: '' as number | '',
        grt: '',
        bhp: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return vessels;
        }

        return vessels.filter((v) => {
            return (
                v.name.toLowerCase().includes(q) ||
                (v.vessel_type?.name ?? '').toLowerCase().includes(q) ||
                String(v.grt ?? '').includes(q) ||
                String(v.bhp ?? '').includes(q)
            );
        });
    }, [vessels, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            vessel_type_id: '',
            grt: '',
            bhp: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (row: VesselRow) => {
        setCurrent(row);
        form.reset();
        form.clearErrors();
        form.setData({
            name: row.name,
            vessel_type_id: row.vessel_type_id,
            grt: row.grt !== null && row.grt !== undefined ? String(row.grt) : '',
            bhp: row.bhp !== null && row.bhp !== undefined ? String(row.bhp) : '',
            is_active: row.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        const payload = {
            name: form.data.name,
            vessel_type_id: form.data.vessel_type_id,
            grt: form.data.grt.trim() === '' ? null : Number(form.data.grt),
            bhp: form.data.bhp.trim() === '' ? null : Number.parseInt(form.data.bhp, 10),
            is_active: form.data.is_active,
        };

        if (current) {
            form.put(`/settings/master-data/vessels/${current.id}`, {
                data: payload,
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/vessels', {
            data: payload,
            preserveScroll: true,
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
                <Heading variant="small" title="Vessels" description="Manage vessels, types, GRT, and BHP used across the system." />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search vessels..." />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                        <Button variant="outline" type="button" onClick={openImport}>
                            <Upload className="mr-2 h-4 w-4" />
                            Import CSV
                        </Button>
                        {can.create ? <Button onClick={openCreate}>Add vessel</Button> : null}
                    </div>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[980px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-3">Name</div>
                                <div className="col-span-3">Vessel type</div>
                                <div className="col-span-2">GRT</div>
                                <div className="col-span-2">BHP</div>
                                <div className="col-span-1">Active</div>
                                <div className="col-span-1 text-right">Actions</div>
                            </div>

                            {rows.map((v) => (
                                <div key={v.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-3 text-sm truncate">{v.name}</div>
                                    <div className="col-span-3 text-sm text-muted-foreground truncate">{v.vessel_type?.name ?? '—'}</div>
                                    <div className="col-span-2 text-sm tabular-nums">{v.grt ?? '—'}</div>
                                    <div className="col-span-2 text-sm tabular-nums">{v.bhp ?? '—'}</div>
                                    <div className="col-span-1 flex items-center">
                                        <Switch disabled={!can.update} checked={v.is_active} onCheckedChange={() => toggleActive(v)} />
                                    </div>
                                    <div className="col-span-1 flex justify-end gap-2">
                                        {can.update ? <Button variant="outline" size="sm" onClick={() => openEdit(v)}>Edit</Button> : null}
                                        {can.delete ? <Button variant="destructive" size="sm" onClick={() => requestDelete(v)}>Delete</Button> : null}
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No vessels found.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
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
                            <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-inner ring-1 ring-primary/15" aria-hidden>
                                <Upload className="size-5" />
                            </div>
                            <div className="min-w-0 space-y-1.5 pt-0.5">
                                <DialogTitle className="text-xl leading-tight">Import vessels</DialogTitle>
                                <DialogDescription>Add or update vessels in bulk. Existing names are updated.</DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="space-y-5 px-6 py-5">
                        <Alert className="border-border/80 bg-muted/40">
                            <Info className="text-primary" aria-hidden />
                            <AlertDescription>
                                <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                                    <li><span className="font-medium text-foreground">name</span> — required</li>
                                    <li><span className="font-medium text-foreground">vessel_type</span> — required (must match an existing type)</li>
                                    <li><span className="font-medium text-foreground">grt</span>, <span className="font-medium text-foreground">bhp</span> — optional</li>
                                </ul>
                            </AlertDescription>
                        </Alert>

                        <Button variant="secondary" type="button" className="w-full sm:w-auto" asChild>
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
                            onChange={(event) => pickImportFile(event.target.files?.[0])}
                        />
                        <div
                            role="button"
                            tabIndex={0}
                            className={cn(
                                'rounded-xl border-2 border-dashed border-border bg-background/80 p-6 text-center',
                                importDragActive && 'border-primary bg-primary/6',
                                importFile && 'border-solid border-emerald-500/40 bg-emerald-500/7',
                            )}
                            onClick={() => fileInputRef.current?.click()}
                            onKeyDown={(event: KeyboardEvent<HTMLDivElement>) => {
                                if (event.key === 'Enter' || event.key === ' ') {
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
                                    <span className="text-sm font-medium">{importFile.name}</span>
                                    <Button type="button" variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); clearImportFile(); }}>Remove</Button>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">Drop your CSV here or click to browse</p>
                            )}
                        </div>

                        {importMessage ? (
                            <Alert variant="destructive">
                                <AlertCircle aria-hidden />
                                <AlertDescription>{importMessage}</AlertDescription>
                            </Alert>
                        ) : null}
                    </div>

                    <DialogFooter className="gap-2 border-t border-border bg-muted/30 px-6 py-4">
                        <Button type="button" variant="outline" disabled={importProcessing} onClick={() => setImportOpen(false)}>Cancel</Button>
                        <Button type="button" disabled={!importFile || importProcessing} onClick={runImport}>
                            {importProcessing ? <><Loader2 className="mr-2 size-4 animate-spin" />Importing…</> : 'Import'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="right" className="flex w-full flex-col rounded-none p-0 glass-card sm:max-w-md">
                    <SheetHeader className="p-8 pb-6 border-b border-border/60">
                        <SheetTitle className="text-xl font-bold tracking-tight">{current ? 'Edit vessel' : 'New vessel'}</SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">GRT and BHP are managed here and shown on sea service records.</SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 overflow-y-auto p-8 space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="ADNOC 951" />
                            {form.errors.name ? <div className="text-xs text-destructive">{form.errors.name}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="vessel_type_id">Vessel type</Label>
                            <AppSelect
                                value={form.data.vessel_type_id === '' ? '' : String(form.data.vessel_type_id)}
                                onValueChange={(v) => form.setData('vessel_type_id', v ? Number(v) : '')}
                                variant="dark"
                                placeholder="Select type"
                            >
                                <AppSelectItem value="">—</AppSelectItem>
                                {vessel_types.map((type) => (
                                    <AppSelectItem key={type.id} value={String(type.id)}>{type.name}</AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.vessel_type_id ? <div className="text-xs text-destructive">{form.errors.vessel_type_id}</div> : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="grt">GRT</Label>
                                <Input id="grt" type="number" min="0" step="0.01" value={form.data.grt} onChange={(e) => form.setData('grt', e.target.value)} />
                                {form.errors.grt ? <div className="text-xs text-destructive">{form.errors.grt}</div> : null}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="bhp">BHP</Label>
                                <Input id="bhp" type="number" min="0" step="1" value={form.data.bhp} onChange={(e) => form.setData('bhp', e.target.value)} />
                                {form.errors.bhp ? <div className="text-xs text-destructive">{form.errors.bhp}</div> : null}
                            </div>
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold">Active</div>
                                <div className="text-xs text-muted-foreground">Disable to hide from selections.</div>
                            </div>
                            <Switch disabled={!can.update} checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </div>
                    </div>

                    <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                        <Button type="button" variant="ghost" className="flex-1" onClick={() => setSheetOpen(false)}>Cancel</Button>
                        <Button type="button" className="flex-1" onClick={submit} disabled={form.processing}>{form.processing ? 'Saving…' : 'Save'}</Button>
                    </div>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete vessel</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current ? `This will permanently delete “${current.name}”.` : 'This will permanently delete this vessel.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
