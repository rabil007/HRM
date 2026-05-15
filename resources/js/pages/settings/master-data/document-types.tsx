import { Head, router, useForm } from '@inertiajs/react';
import { AlertCircle, Download, FileSpreadsheet, Info, Loader2, Upload } from 'lucide-react';
import { useMemo, useRef, useState   } from 'react';
import type {DragEvent, KeyboardEvent} from 'react';
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
import { cn } from '@/lib/utils';

type DocumentType = {
    id: number;
    title: string;
    is_active: boolean;
};

export default function DocumentTypes({ document_types }: { document_types: DocumentType[] }) {
    const [query, setQuery] = useState('');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<DocumentType | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        title: '',
        is_active: true,
    });

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) {
            return document_types;
        }

        return document_types.filter((d) => d.title.toLowerCase().includes(q));
    }, [document_types, query]);

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            title: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (doc: DocumentType) => {
        setCurrent(doc);
        form.reset();
        form.clearErrors();
        form.setData({
            title: doc.title,
            is_active: doc.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(`/settings/master-data/document-types/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/document-types', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (doc: DocumentType) => {
        setCurrent(doc);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/document-types/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (doc: DocumentType) => {
        router.put(
            `/settings/master-data/document-types/${doc.id}`,
            {
                title: doc.title,
                is_active: !doc.is_active,
            },
            {
                preserveScroll: true,
            }
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
            '/settings/master-data/document-types/import',
            { file: importFile },
            {
                preserveScroll: true,
                forceFormData: true,
                onFinish: () => setImportProcessing(false),
                onSuccess: () => {
                    setImportOpen(false);
                    setImportFile(null);
                    setImportMessage(null);

                    if (fileInputRef.current) {
                        fileInputRef.current.value = '';
                    }
                },
                onError: (errs) => setImportMessage((errs as { file?: string }).file ?? 'Import failed.'),
            }
        );
    };

    return (
        <>
            <Head title="Document types" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Document types"
                    description="Manage employee document type labels used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by title…"
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                        <Button variant="outline" type="button" onClick={openImport}>
                            <Upload className="mr-2 h-4 w-4" />
                            Import CSV
                        </Button>
                        <Button onClick={openCreate}>Add document type</Button>
                    </div>
                </div>

                <div className="rounded-xl border border-border/60 overflow-hidden">
                    <div className="overflow-x-auto">
                        <div className="min-w-[640px]">
                            <div className="grid grid-cols-12 gap-2 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground bg-muted/30 whitespace-nowrap">
                                <div className="col-span-8">Title</div>
                                <div className="col-span-1">Active</div>
                                <div className="col-span-3 text-right">Actions</div>
                            </div>

                            {rows.map((d) => (
                                <div key={d.id} className="grid grid-cols-12 gap-2 px-4 py-3 border-t border-border/60 whitespace-nowrap">
                                    <div className="col-span-8 text-sm font-medium truncate">{d.title}</div>
                                    <div className="col-span-1 flex items-center">
                                        <Switch checked={d.is_active} onCheckedChange={() => toggleActive(d)} />
                                    </div>
                                    <div className="col-span-3 flex justify-end gap-2 flex-nowrap">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(d)}>
                                            Edit
                                        </Button>
                                        <Button variant="destructive" size="sm" onClick={() => requestDelete(d)}>
                                            Delete
                                        </Button>
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">No document types found.</div>
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
                            <div
                                className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-inner ring-1 ring-primary/15"
                                aria-hidden
                            >
                                <Upload className="size-5" />
                            </div>
                            <div className="min-w-0 space-y-1.5 pt-0.5">
                                <DialogTitle className="text-xl leading-tight">Import document types</DialogTitle>
                                <DialogDescription>
                                    Add or update rows in bulk. Existing titles are updated; new titles are created.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="space-y-5 px-6 py-5">
                        <Alert className="border-border/80 bg-muted/40">
                            <Info className="text-primary" aria-hidden />
                            <AlertDescription>
                                <span className="sr-only">CSV format:</span>
                                <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                                    <li>
                                        <span className="font-medium text-foreground">title</span> — required header and value on each row
                                    </li>
                                    <li>
                                        <span className="font-medium text-foreground">is_active</span> — optional; use yes, true, 1, or active for
                                        enabled
                                    </li>
                                </ul>
                            </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Step 1 — Template</p>
                            <div className="rounded-xl border border-border/80 bg-muted/20 p-4">
                                <p className="text-sm text-muted-foreground">
                                    Download a file with the correct column headers so your import validates cleanly.
                                </p>
                                <Button variant="secondary" type="button" className="mt-3 w-full sm:w-auto" asChild>
                                    <a href="/settings/master-data/document-types/import/template">
                                        <Download className="mr-2 size-4" />
                                        Download CSV template
                                    </a>
                                </Button>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Step 2 — Upload
                            </p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
                                className="sr-only"
                                id="document-types-import-file"
                                onChange={(event) => {
                                    pickImportFile(event.target.files?.[0]);
                                }}
                            />
                            <div
                                role="button"
                                tabIndex={0}
                                aria-label="Select or drop a CSV file"
                                className={cn(
                                    'group relative rounded-xl border-2 border-dashed border-border bg-background/80 p-6 text-center transition-[color,background-color,border-color,box-shadow] outline-none',
                                    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/35',
                                    importDragActive && 'border-primary bg-primary/6 ring-2 ring-primary/25 ring-offset-2 ring-offset-background',
                                    importFile &&
                                        'border-solid border-emerald-500/40 bg-emerald-500/7 hover:bg-emerald-500/9',
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
                                    <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between sm:text-left">
                                        <div className="flex min-w-0 items-start gap-3">
                                            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                                <FileSpreadsheet className="size-5" />
                                            </div>
                                            <div className="min-w-0 pt-0.5">
                                                <p className="truncate text-sm font-medium text-foreground">{importFile.name}</p>
                                                <p className="text-xs text-muted-foreground">Ready to import</p>
                                            </div>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="shrink-0 text-muted-foreground hover:text-foreground"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                clearImportFile();
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center gap-2">
                                        <div className="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
                                            <Upload className="size-6" />
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-foreground">Drop your CSV here</p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                or click to browse — <span className="text-foreground/80">.csv</span> files only
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {importMessage ? (
                            <Alert variant="destructive" className="border-destructive/40">
                                <AlertCircle aria-hidden />
                                <AlertDescription>{importMessage}</AlertDescription>
                            </Alert>
                        ) : null}
                    </div>

                    <DialogFooter className="gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:justify-end">
                        <Button type="button" variant="outline" disabled={importProcessing} onClick={() => setImportOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="default"
                            disabled={!importFile || importProcessing}
                            onClick={runImport}
                            aria-busy={importProcessing}
                        >
                            {importProcessing ? (
                                <>
                                    <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
                                    Importing…
                                </>
                            ) : (
                                <>
                                    <Upload className="mr-2 size-4" aria-hidden />
                                    Import
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent
                    side="right"
                    className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0 flex flex-col"
                >
                    <SheetHeader className="p-8 pb-6 border-b border-white/5">
                        <SheetTitle className="text-xl font-bold tracking-tight text-white">
                            {current ? 'Edit document type' : 'New document type'}
                        </SheetTitle>
                        <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                            Used for employee documents and onboarding requirements.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                        className="flex-1 overflow-y-auto p-8 space-y-5"
                    >
                        <div className="space-y-2">
                            <Label
                                htmlFor="title"
                                className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70"
                            >
                                Title
                            </Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.title ? <div className="text-xs text-destructive">{form.errors.title}</div> : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border bg-card p-3">
                            <div>
                                <div className="text-sm font-semibold">Active</div>
                                <div className="text-xs text-muted-foreground">Visible in dropdowns and templates.</div>
                            </div>
                            <Switch checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                        </div>

                        <div className="pt-2 flex items-center justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setSheetOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {current ? 'Save changes' : 'Create'}
                            </Button>
                        </div>
                    </form>
                </SheetContent>
            </Sheet>

            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete document type?</AlertDialogTitle>
                        <AlertDialogDescription>This action cannot be undone.</AlertDialogDescription>
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
