import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    FileText,
    Search,
    Terminal,
    Trash2,
    Download,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    destroy as clearApplicationLogs,
    exportMethod as exportApplicationLogs,
} from '@/actions/App/Http/Controllers/ApplicationLogController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type LogFile = {
    name: string;
    size_bytes: number;
    modified_at: string;
};

type LogEntry = {
    id: string;
    logged_at: string;
    environment: string;
    level: string;
    message: string;
    context: Record<string, unknown> | null;
    stack: string | null;
};

type Props = {
    entries: LogEntry[];
    pagination: PaginationMeta;
    files: LogFile[];
    levels: string[];
    filters: {
        file: string;
        level: string;
        q: string;
    };
    file_meta: {
        name: string;
        size_bytes: number;
        modified_at: string;
        truncated: boolean;
    };
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function levelStyle(level: string): string {
    switch (level) {
        case 'error':
        case 'critical':
        case 'alert':
        case 'emergency':
            return 'bg-red-500/10 text-red-500 border-red-500/20';
        case 'warning':
            return 'bg-amber-500/10 text-amber-500 border-amber-500/20';
        case 'info':
        case 'notice':
            return 'bg-sky-500/10 text-sky-500 border-sky-500/20';
        default:
            return 'bg-zinc-500/10 text-muted-foreground border-border/60';
    }
}

export default function ApplicationLogViewer({
    entries,
    pagination,
    files,
    levels,
    filters,
    file_meta,
}: Props) {
    const form = useForm({
        file: filters.file ?? '',
        level: filters.level ?? '',
        q: filters.q ?? '',
    });

    const [openId, setOpenId] = useState<string | null>(null);
    const [clearFileOpen, setClearFileOpen] = useState(false);
    const [clearAllOpen, setClearAllOpen] = useState(false);
    const [isClearing, setIsClearing] = useState(false);

    const list = useServerPaginationFilters({
        url: '/log',
        search: filters.q ?? '',
        filters: {
            file: filters.file,
            level: filters.level,
        },
        pagination,
        searchKey: 'q',
    });

    const submitFilters = (overrides?: Partial<typeof form.data>) => {
        const data = { ...form.data, ...(overrides ?? {}) };
        form.setData(data);
        list.visit({ ...data, page: null });
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (form.data.q) {
            count++;
        }

        if (form.data.level) {
            count++;
        }

        if (form.data.file && files[0] && form.data.file !== files[0].name) {
            count++;
        }

        return count;
    }, [files, form.data]);

    const clearLogs = (scope: 'current' | 'all') => {
        setIsClearing(true);

        router.delete(clearApplicationLogs.url(), {
            data: {
                scope,
                file: form.data.file || file_meta.name || files[0]?.name || '',
                level: form.data.level,
                q: form.data.q,
            },
            preserveScroll: true,
            onFinish: () => {
                setIsClearing(false);
                setClearFileOpen(false);
                setClearAllOpen(false);
            },
        });
    };

    const hasLogFiles = files.length > 0;
    const selectedFileName =
        form.data.file || file_meta.name || files[0]?.name || '';

    return (
        <>
            <Head title="Application logs" />

            <Main>
                <div className="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <div className="mb-1 flex items-center gap-2">
                            <Terminal className="size-4 text-primary" />
                            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                Diagnostics
                            </span>
                        </div>
                        <h1 className="text-3xl font-extrabold tracking-tight text-foreground">
                            Application logs
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Read Laravel log files without opening the server
                            filesystem.
                        </p>
                    </div>

                    <div className="flex flex-col items-stretch gap-3 sm:items-end">
                        {hasLogFiles ? (
                            <div className="flex flex-wrap justify-end gap-2">
                                {selectedFileName ? (
                                    <Button
                                        asChild
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-9 gap-1.5 border-border/60 hover:bg-muted/10"
                                    >
                                        <a
                                            href={exportApplicationLogs.url({
                                                query: {
                                                    file: selectedFileName,
                                                },
                                            })}
                                            download
                                        >
                                            <Download className="size-3.5" />
                                            Export
                                        </a>
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="h-9 gap-1.5 border-border/60"
                                        disabled
                                    >
                                        <Download className="size-3.5" />
                                        Export
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="h-9 gap-1.5 border-red-500/30 text-red-500 hover:bg-red-500/10 hover:text-red-500"
                                    disabled={isClearing || !selectedFileName}
                                    onClick={() => setClearFileOpen(true)}
                                >
                                    <Trash2 className="size-3.5" />
                                    Clear file
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    className="h-9 gap-1.5"
                                    disabled={isClearing}
                                    onClick={() => setClearAllOpen(true)}
                                >
                                    <Trash2 className="size-3.5" />
                                    Clear all logs
                                </Button>
                            </div>
                        ) : null}

                        {file_meta.name ? (
                            <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 text-xs text-muted-foreground">
                                <div className="flex items-center gap-2 font-medium text-foreground">
                                    <FileText className="size-3.5" />
                                    {file_meta.name}
                                </div>
                                <div className="mt-1">
                                    {formatBytes(file_meta.size_bytes)} ·
                                    updated{' '}
                                    {new Date(
                                        file_meta.modified_at,
                                    ).toLocaleString()}
                                </div>
                                {file_meta.truncated ? (
                                    <div className="mt-2 flex items-center gap-1.5 text-amber-500">
                                        <AlertTriangle className="size-3.5" />
                                        Showing the latest portion of this file
                                        only.
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </div>

                <Card className="mb-6 border-border/60 bg-card/50">
                    <CardContent className="grid gap-4 p-4 md:grid-cols-4">
                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Log file
                            </label>
                            <AppSelect
                                value={form.data.file}
                                onValueChange={(value) =>
                                    submitFilters({ file: value })
                                }
                                variant="dark"
                            >
                                {files.map((file) => (
                                    <AppSelectItem
                                        key={file.name}
                                        value={file.name}
                                    >
                                        {file.name} (
                                        {formatBytes(file.size_bytes)})
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="space-y-1.5">
                            <label className="text-xs font-medium text-muted-foreground">
                                Level
                            </label>
                            <AppSelect
                                value={form.data.level || 'all'}
                                onValueChange={(value) =>
                                    submitFilters({
                                        level: value === 'all' ? '' : value,
                                    })
                                }
                                variant="dark"
                            >
                                <AppSelectItem value="all">
                                    All levels
                                </AppSelectItem>
                                {levels.map((level) => (
                                    <AppSelectItem key={level} value={level}>
                                        {level.toUpperCase()}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        <div className="space-y-1.5 md:col-span-2">
                            <label className="text-xs font-medium text-muted-foreground">
                                Search
                            </label>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={form.data.q}
                                        onChange={(event) => {
                                            form.setData(
                                                'q',
                                                event.target.value,
                                            );
                                            list.onSearchChange(
                                                event.target.value,
                                            );
                                        }}
                                        placeholder="Search message, context, or stack trace"
                                        className="h-10 rounded-xl border-border/60 bg-muted/50 pl-9"
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 rounded-xl"
                                    onClick={() => {
                                        form.setData({
                                            file: files[0]?.name ?? '',
                                            level: '',
                                            q: '',
                                        });
                                        list.visit({
                                            file: files[0]?.name ?? '',
                                            level: '',
                                            q: '',
                                            page: null,
                                        });
                                    }}
                                    disabled={activeFilterCount === 0}
                                >
                                    Reset
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {entries.length === 0 ? (
                    <Card className="border-dashed border-border/60">
                        <CardContent className="flex flex-col items-center justify-center gap-2 py-16 text-center">
                            <Terminal className="size-10 text-muted-foreground/30" />
                            <p className="text-sm font-medium text-foreground">
                                No log entries found
                            </p>
                            <p className="max-w-md text-xs text-muted-foreground">
                                Try another file, clear filters, or generate
                                activity in the app to populate the log.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {entries.map((entry) => {
                            const isOpen = openId === entry.id;
                            const hasDetails =
                                entry.context !== null ||
                                (entry.stack !== null &&
                                    entry.stack.trim() !== '');

                            return (
                                <Card
                                    key={entry.id}
                                    className="overflow-hidden border-border/60 bg-card/40"
                                >
                                    <CardContent className="p-0">
                                        <Collapsible
                                            open={isOpen}
                                            onOpenChange={(open) =>
                                                setOpenId(
                                                    open ? entry.id : null,
                                                )
                                            }
                                        >
                                            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="min-w-0 flex-1 space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge
                                                            variant="outline"
                                                            className={cn(
                                                                'rounded-full px-2 py-0.5 text-[10px] font-bold uppercase',
                                                                levelStyle(
                                                                    entry.level,
                                                                ),
                                                            )}
                                                        >
                                                            {entry.level}
                                                        </Badge>
                                                        <span className="text-xs text-muted-foreground">
                                                            {entry.logged_at}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {entry.environment}
                                                        </span>
                                                    </div>
                                                    <p className="font-mono text-sm wrap-break-word text-foreground">
                                                        {entry.message ||
                                                            '(empty message)'}
                                                    </p>
                                                </div>

                                                {hasDetails ? (
                                                    <CollapsibleTrigger asChild>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="h-8 shrink-0 rounded-lg text-xs"
                                                        >
                                                            {isOpen
                                                                ? 'Hide details'
                                                                : 'View details'}
                                                        </Button>
                                                    </CollapsibleTrigger>
                                                ) : null}
                                            </div>

                                            {hasDetails ? (
                                                <CollapsibleContent>
                                                    <div className="space-y-3 border-t border-border/60 bg-muted/20 p-4">
                                                        {entry.context ? (
                                                            <div>
                                                                <div className="mb-2 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                                                    Context
                                                                </div>
                                                                <pre className="overflow-x-auto rounded-xl border border-border/60 bg-background/80 p-3 font-mono text-xs text-foreground/90">
                                                                    {JSON.stringify(
                                                                        entry.context,
                                                                        null,
                                                                        2,
                                                                    )}
                                                                </pre>
                                                            </div>
                                                        ) : null}
                                                        {entry.stack ? (
                                                            <div>
                                                                <div className="mb-2 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                                                    Stack /
                                                                    details
                                                                </div>
                                                                <pre className="overflow-x-auto rounded-xl border border-border/60 bg-background/80 p-3 font-mono text-xs whitespace-pre-wrap text-foreground/90">
                                                                    {
                                                                        entry.stack
                                                                    }
                                                                </pre>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </CollapsibleContent>
                                            ) : null}
                                        </Collapsible>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                <Pagination
                    {...list.paginationProps}
                    perPageOptions={[25, 50, 100]}
                    label="entries"
                />
            </Main>

            <ConfirmDeleteDialog
                open={clearFileOpen}
                onOpenChange={setClearFileOpen}
                title="Clear this log file?"
                description={`All entries in ${selectedFileName} will be permanently removed. The empty file will remain so Laravel can keep writing new logs.`}
                confirmText={isClearing ? 'Clearing…' : 'Clear file'}
                onConfirm={() => clearLogs('current')}
                contentClassName="sm:max-w-md"
                confirmButtonClassName="bg-red-600 text-white hover:bg-red-500"
            />

            <ConfirmDeleteDialog
                open={clearAllOpen}
                onOpenChange={setClearAllOpen}
                title="Clear all log files?"
                description="Every laravel log file in storage/logs will be emptied. This cannot be undone."
                confirmText={isClearing ? 'Clearing…' : 'Clear all logs'}
                onConfirm={() => clearLogs('all')}
                contentClassName="sm:max-w-md"
                confirmButtonClassName="bg-red-600 text-white hover:bg-red-500"
            />
        </>
    );
}
