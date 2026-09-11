import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Download,
    FileSpreadsheet,
    Info,
    Loader2,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { MasterDataDeleteButton } from '@/components/settings/master-data-delete-button';
import { MasterDataInUseBadge } from '@/components/settings/master-data-in-use-badge';
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
import type { MasterDataUsageFlags } from '@/lib/master-data/usage';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type ClientOption = {
    id: number;
    name: string;
    is_active: boolean;
};

type Project = {
    id: number;
    client_id: number | null;
    client_name: string | null;
    title: string;
    is_active: boolean;
} & MasterDataUsageFlags;

const fieldLabelClass =
    'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase';

export default function Projects({
    projects,
    clients = [],
    pagination,
    search = '',
    filters = { client_id: null },
}: {
    projects: Project[];
    clients?: ClientOption[];
    pagination: PaginationMeta;
    search?: string;
    filters?: { client_id: number | null };
}) {
    const can = useSettingsMasterDataCan('projects');

    const list = useServerPaginationFilters({
        url: '/settings/master-data/projects',
        search,
        filters: {
            client_id: filters.client_id ? String(filters.client_id) : '',
        },
        pagination,
    });

    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [current, setCurrent] = useState<Project | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<{
        client_id: number | '';
        title: string;
        is_active: boolean;
    }>({
        client_id: '',
        title: '',
        is_active: true,
    });

    const rows = projects;
    const activeClients = clients.filter((client) => client.is_active);
    const formClients =
        current?.client_id &&
        !activeClients.some((client) => client.id === current.client_id)
            ? [
                  ...activeClients,
                  {
                      id: current.client_id,
                      name:
                          current.client_name ?? `Client #${current.client_id}`,
                      is_active: false,
                  },
              ]
            : activeClients;

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData({
            client_id: '',
            title: '',
            is_active: true,
        });
        setSheetOpen(true);
    };

    const openEdit = (project: Project) => {
        setCurrent(project);
        form.reset();
        form.clearErrors();
        form.setData({
            client_id: project.client_id ?? '',
            title: project.title,
            is_active: project.is_active,
        });
        setSheetOpen(true);
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            client_id: data.client_id === '' ? null : data.client_id,
        }));

        if (current) {
            form.put(`/settings/master-data/projects/${current.id}`, {
                preserveScroll: true,
                onSuccess: () => setSheetOpen(false),
            });

            return;
        }

        form.post('/settings/master-data/projects', {
            preserveScroll: true,
            onSuccess: () => setSheetOpen(false),
        });
    };

    const requestDelete = (project: Project) => {
        setCurrent(project);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(`/settings/master-data/projects/${current.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (project: Project) => {
        router.put(
            `/settings/master-data/projects/${project.id}`,
            {
                client_id: project.client_id,
                title: project.title,
                is_active: !project.is_active,
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
            '/settings/master-data/projects/import',
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
            <Head title="Projects" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Projects"
                    description="Manage projects used across the system."
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                        <Input
                            value={list.searchInput}
                            onChange={(e) =>
                                list.onSearchChange(e.target.value)
                            }
                            placeholder="Search projects..."
                            className="sm:max-w-xs"
                        />
                        <AppSelect
                            value={
                                filters.client_id
                                    ? String(filters.client_id)
                                    : ''
                            }
                            onValueChange={(clientId) =>
                                list.applyFilters({ client_id: clientId })
                            }
                            placeholder="All clients"
                            variant="dark"
                            className="h-10 sm:w-56"
                        >
                            <AppSelectItem value="">All clients</AppSelectItem>
                            {clients.map((client) => (
                                <AppSelectItem
                                    key={client.id}
                                    value={String(client.id)}
                                >
                                    {client.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
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
                            <Button onClick={openCreate}>Add project</Button>
                        ) : null}
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <div className="min-w-[720px]">
                            <div className="grid grid-cols-12 gap-2 bg-muted/30 px-4 py-3 text-xs font-semibold tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                                <div className="col-span-4">Title</div>
                                <div className="col-span-3">Client</div>
                                <div className="col-span-2">Active</div>
                                <div className="col-span-3 text-right">
                                    Actions
                                </div>
                            </div>

                            {rows.map((p) => (
                                <div
                                    key={p.id}
                                    className="grid grid-cols-12 gap-2 border-t border-border/60 px-4 py-3 whitespace-nowrap"
                                >
                                    <div className="col-span-4 flex min-w-0 items-center gap-2 text-sm">
                                        <span className="truncate">
                                            {p.title}
                                        </span>
                                        <MasterDataInUseBadge item={p} />
                                    </div>
                                    <div className="col-span-3 truncate text-sm text-muted-foreground">
                                        {p.client_name ?? 'Unassigned'}
                                    </div>
                                    <div className="col-span-2 flex items-center">
                                        <Switch
                                            disabled={!can.update}
                                            checked={p.is_active}
                                            onCheckedChange={() =>
                                                toggleActive(p)
                                            }
                                        />
                                    </div>
                                    <div className="col-span-3 flex flex-nowrap justify-end gap-2">
                                        {can.update ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(p)}
                                            >
                                                Edit
                                            </Button>
                                        ) : null}
                                        <MasterDataDeleteButton
                                            item={p}
                                            hasDeletePermission={can.delete}
                                            onDelete={() => requestDelete(p)}
                                        />
                                    </div>
                                </div>
                            ))}

                            {rows.length === 0 ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">
                                    No projects found.
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <Pagination {...list.paginationProps} label="projects" />
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
                                    Import projects
                                </DialogTitle>
                                <DialogDescription>
                                    Add or update rows in bulk. Existing titles
                                    are updated; new titles are created.
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
                                        <span className="font-medium text-foreground">
                                            client
                                        </span>{' '}
                                        — required; must match an existing
                                        client name
                                    </li>
                                    <li>
                                        <span className="font-medium text-foreground">
                                            project
                                        </span>{' '}
                                        — required project title on each row
                                    </li>
                                    <li>
                                        <span className="font-medium text-foreground">
                                            is_active
                                        </span>{' '}
                                        — optional; use yes, true, 1, or active
                                        for enabled
                                    </li>
                                </ul>
                            </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Step 1 — Template
                            </p>
                            <div className="rounded-xl border border-border/80 bg-muted/20 p-4">
                                <p className="text-sm text-muted-foreground">
                                    Download a file with the correct column
                                    headers so your import validates cleanly.
                                </p>
                                <Button
                                    variant="secondary"
                                    type="button"
                                    className="mt-3 w-full sm:w-auto"
                                    asChild
                                >
                                    <a href="/settings/master-data/projects/import/template">
                                        <Download className="mr-2 size-4" />
                                        Download CSV template
                                    </a>
                                </Button>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Step 2 — Upload
                            </p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
                                className="sr-only"
                                id="projects-import-file"
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
                                    importDragActive &&
                                        'border-primary bg-primary/6 ring-2 ring-primary/25 ring-offset-2 ring-offset-background',
                                    importFile &&
                                        'border-solid border-emerald-500/40 bg-emerald-500/7 hover:bg-emerald-500/9',
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
                                    <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between sm:text-left">
                                        <div className="flex min-w-0 items-start gap-3">
                                            <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                                <FileSpreadsheet className="size-5" />
                                            </div>
                                            <div className="min-w-0 pt-0.5">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {importFile.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Ready to import
                                                </p>
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
                                            <p className="text-sm font-medium text-foreground">
                                                Drop your CSV here
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                or click to browse —{' '}
                                                <span className="text-foreground/80">
                                                    .csv
                                                </span>{' '}
                                                files only
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {importMessage ? (
                            <Alert
                                variant="destructive"
                                className="border-destructive/40"
                            >
                                <AlertCircle aria-hidden />
                                <AlertDescription>
                                    {importMessage}
                                </AlertDescription>
                            </Alert>
                        ) : null}
                    </div>

                    <DialogFooter className="gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:justify-end">
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
                            variant="default"
                            disabled={!importFile || importProcessing}
                            onClick={runImport}
                            aria-busy={importProcessing}
                        >
                            {importProcessing ? (
                                <>
                                    <Loader2
                                        className="mr-2 size-4 animate-spin"
                                        aria-hidden
                                    />
                                    Importing…
                                </>
                            ) : (
                                <>
                                    <Upload
                                        className="mr-2 size-4"
                                        aria-hidden
                                    />
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
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
                >
                    <SheetHeader className="border-b border-border/60 p-8 pb-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            {current ? 'Edit project' : 'New project'}
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                            Keep titles short and consistent.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex-1 space-y-5 overflow-y-auto p-8">
                        <div className="space-y-2">
                            <Label
                                htmlFor="client_id"
                                className={fieldLabelClass}
                            >
                                Client
                                {!current ? (
                                    <span className="text-destructive"> *</span>
                                ) : null}
                            </Label>
                            <AppSelect
                                value={
                                    form.data.client_id === ''
                                        ? ''
                                        : String(form.data.client_id)
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'client_id',
                                        value ? Number(value) : '',
                                    )
                                }
                                variant="dark"
                                placeholder="Select client"
                                className="h-11 rounded-xl"
                            >
                                {current && current.client_id === null ? (
                                    <AppSelectItem value="">
                                        Unassigned
                                    </AppSelectItem>
                                ) : null}
                                {formClients.map((client) => (
                                    <AppSelectItem
                                        key={client.id}
                                        value={String(client.id)}
                                    >
                                        {client.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.client_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.client_id}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="title" className={fieldLabelClass}>
                                Project Name
                            </Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="North Field"
                                className="h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40"
                            />
                            {form.errors.title ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.title}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-foreground">
                                    Active
                                </div>
                                <div className="text-xs text-muted-foreground/80">
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
                            className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                            onClick={() => setSheetOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="h-11 flex-1 rounded-xl px-6 font-semibold"
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
                        <AlertDialogTitle>Delete project</AlertDialogTitle>
                        <AlertDialogDescription>
                            {current
                                ? `This will permanently delete “${current.title}”.`
                                : 'This will permanently delete this project.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className="rounded-xl"
                            onClick={confirmDelete}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
