import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Clock3,
    Copy,
    Database,
    Hourglass,
    RotateCcw,
    Search,
    Terminal,
    Timer,
    Trash2,
    Workflow,
    Info,
    Calendar,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    destroyAllFailed as destroyAllFailedJobs,
    destroyAllHistory as destroyAllHistoryRuns,
    destroyAllPending as destroyAllPendingJobs,
    destroyFailed as destroyFailedJob,
    destroyHistory as destroyHistoryRun,
    destroyPending as destroyPendingJob,
    index as jobsIndex,
    retryAllFailed as retryAllFailedJobs,
    retryFailed as retryFailedJob,
} from '@/actions/App/Http/Controllers/JobRunController';
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
import { formatDisplayDateTimeInTimezone } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type Tab = 'history' | 'failed' | 'pending' | 'registry';

type HistoryRun = {
    id: number;
    correlation_id: string | null;
    type: string;
    name: string;
    status: string;
    queue: string | null;
    connection: string | null;
    trigger: string | null;
    context: Record<string, unknown> | null;
    message: string | null;
    exception: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
};

type FailedJob = {
    id: number;
    uuid: string;
    name: string;
    queue: string;
    connection: string;
    failed_at: string;
    exception: string;
    exception_summary: string;
    payload: Record<string, unknown> | null;
};

type PendingJob = {
    id: number;
    name: string;
    queue: string;
    attempts: number;
    reserved_at: string | null;
    available_at: string;
    created_at: string;
    payload: Record<string, unknown> | null;
};

type RegistryItem = {
    type: 'job' | 'command';
    name: string;
    class: string;
    purpose: string;
    trigger: string;
    queue?: string;
    connection?: string;
    schedule?: string;
    signature?: string;
    parameters?: Record<string, string>;
    details?: string;
    code_snippet: string;
};

type Stats = {
    history_count: number;
    completed_count: number;
    failed_count: number;
    pending_count: number;
    avg_duration_ms: number;
};

type Props = {
    tab: Tab;
    history_runs: HistoryRun[];
    failed_jobs: FailedJob[];
    pending_jobs: PendingJob[];
    pagination: PaginationMeta;
    names: string[];
    statuses: string[];
    stats: Stats;
    registry: RegistryItem[];
    scheduler_timezone: string;
    filters: {
        status: string;
        name: string;
        q: string;
        date_from: string;
        date_to: string;
    };
};

function statusStyle(status: string): string {
    switch (status) {
        case 'failed':
            return 'bg-rose-500/10 text-rose-500 border-rose-500/20 dark:bg-rose-500/20 dark:text-rose-400 dark:border-rose-500/30';
        case 'running':
            return 'bg-amber-500/10 text-amber-500 border-amber-500/20 dark:bg-amber-500/20 dark:text-amber-400 dark:border-amber-500/30';
        case 'completed':
            return 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20 dark:bg-emerald-500/20 dark:text-emerald-400 dark:border-emerald-500/30';
        default:
            return 'bg-zinc-500/10 text-muted-foreground border-border/60 dark:bg-zinc-500/20 dark:text-zinc-400';
    }
}

function formatDuration(durationMs: number | null): string {
    if (durationMs === null) {
        return '—';
    }

    if (durationMs < 1000) {
        return `${durationMs} ms`;
    }

    return `${(durationMs / 1000).toFixed(2)} s`;
}

function tabButtonClass(active: boolean): string {
    return cn(
        'rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
        active
            ? 'bg-primary text-primary-foreground shadow-sm'
            : 'text-muted-foreground hover:bg-muted/40 hover:text-foreground',
    );
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = (e: React.MouseEvent) => {
        e.stopPropagation();
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-6 w-6 rounded-md text-muted-foreground transition-all duration-200 hover:bg-muted/80 hover:text-foreground"
            onClick={handleCopy}
            title="Copy to clipboard"
        >
            {copied ? (
                <Check className="size-3 text-emerald-500" />
            ) : (
                <Copy className="size-3" />
            )}
        </Button>
    );
}

export default function JobRunsViewer({
    tab,
    history_runs,
    failed_jobs,
    pending_jobs,
    pagination,
    names,
    statuses,
    stats,
    registry,
    scheduler_timezone,
    filters,
}: Props) {
    const formatDateTime = (value: string | null | undefined) =>
        formatDisplayDateTimeInTimezone(value, scheduler_timezone);
    const [openId, setOpenId] = useState<string | null>(null);
    const [deleteUuid, setDeleteUuid] = useState<string | null>(null);
    const [deleteHistoryId, setDeleteHistoryId] = useState<number | null>(null);
    const [deletePendingId, setDeletePendingId] = useState<number | null>(null);
    const [isRetrying, setIsRetrying] = useState(false);
    const [isRetryingAll, setIsRetryingAll] = useState(false);
    const [showDeleteAllConfirm, setShowDeleteAllConfirm] = useState(false);
    const [showDeleteAllHistoryConfirm, setShowDeleteAllHistoryConfirm] =
        useState(false);
    const [showDeleteAllPendingConfirm, setShowDeleteAllPendingConfirm] =
        useState(false);

    const list = useServerPaginationFilters({
        url: jobsIndex.url(),
        search: filters.q ?? '',
        filters: {
            tab,
            status: filters.status,
            name: filters.name,
            date_from: filters.date_from,
            date_to: filters.date_to,
        },
        pagination,
        searchKey: 'q',
    });

    const submitFilters = (
        overrides?: Partial<typeof filters & { tab?: Tab }>,
    ) => {
        list.visit({ ...overrides, page: null });
    };

    const switchTab = (nextTab: Tab) => {
        submitFilters({
            tab: nextTab,
            status: '',
            name: '',
            q: '',
            date_from: '',
            date_to: '',
        });
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (filters.q) {
            count++;
        }

        if (tab === 'history') {
            if (filters.status) {
                count++;
            }

            if (filters.name) {
                count++;
            }

            if (filters.date_from) {
                count++;
            }

            if (filters.date_to) {
                count++;
            }
        }

        return count;
    }, [filters, tab]);

    const retryFailed = (uuid: string) => {
        setIsRetrying(true);

        router.post(
            retryFailedJob.url(uuid),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsRetrying(false),
            },
        );
    };

    const retryAllFailed = () => {
        setIsRetryingAll(true);

        router.post(
            retryAllFailedJobs.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsRetryingAll(false),
            },
        );
    };

    const deleteFailed = () => {
        if (!deleteUuid) {
            return;
        }

        router.delete(destroyFailedJob.url(deleteUuid), {
            preserveScroll: true,
            onFinish: () => setDeleteUuid(null),
        });
    };

    const deleteAllFailed = () => {
        router.delete(destroyAllFailedJobs.url(), {
            preserveScroll: true,
            onFinish: () => setShowDeleteAllConfirm(false),
        });
    };

    const deleteHistory = () => {
        if (deleteHistoryId === null) {
            return;
        }

        router.delete(destroyHistoryRun.url(deleteHistoryId), {
            preserveScroll: true,
            onFinish: () => setDeleteHistoryId(null),
        });
    };

    const deleteAllHistory = () => {
        router.delete(destroyAllHistoryRuns.url(), {
            preserveScroll: true,
            onFinish: () => setShowDeleteAllHistoryConfirm(false),
        });
    };

    const deletePending = () => {
        if (deletePendingId === null) {
            return;
        }

        router.delete(destroyPendingJob.url(deletePendingId), {
            preserveScroll: true,
            onFinish: () => setDeletePendingId(null),
        });
    };

    const deleteAllPending = () => {
        router.delete(destroyAllPendingJobs.url(), {
            preserveScroll: true,
            onFinish: () => setShowDeleteAllPendingConfirm(false),
        });
    };

    const rowsCount =
        tab === 'history'
            ? history_runs.length
            : tab === 'failed'
              ? failed_jobs.length
              : pending_jobs.length;

    // Filter registry items client-side based on search query
    const filteredRegistry = useMemo(() => {
        const query = (filters.q || list.searchInput || '')
            .toLowerCase()
            .trim();

        if (!query) {
            return registry;
        }

        return registry.filter(
            (item) =>
                item.name.toLowerCase().includes(query) ||
                item.purpose.toLowerCase().includes(query) ||
                item.class.toLowerCase().includes(query) ||
                (item.signature &&
                    item.signature.toLowerCase().includes(query)),
        );
    }, [registry, filters.q, list.searchInput]);

    const jobsList = useMemo(
        () => filteredRegistry.filter((item) => item.type === 'job'),
        [filteredRegistry],
    );
    const commandsList = useMemo(
        () => filteredRegistry.filter((item) => item.type === 'command'),
        [filteredRegistry],
    );

    return (
        <>
            <Head title="Job runs" />

            <Main>
                <div className="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <div className="mb-1 flex items-center gap-2">
                            <Workflow className="size-4 text-primary" />
                            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                Diagnostics
                            </span>
                        </div>
                        <h1 className="text-3xl font-extrabold tracking-tight text-foreground">
                            Job runs
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Track queue history, failed jobs, and pending work.
                            Times use{' '}
                            <span className="font-medium text-foreground">
                                {scheduler_timezone}
                            </span>
                            .
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            asChild
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-9 transition-colors duration-200"
                        >
                            <Link href="/log">
                                <Terminal className="size-3.5" />
                                Application logs
                            </Link>
                        </Button>
                        <Button
                            asChild
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-9 transition-colors duration-200"
                        >
                            <Link href="/mysql">
                                <Database className="size-3.5" />
                                MySQL viewer
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Statistics Row */}
                <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {/* Completed Runs */}
                    <Card className="group relative overflow-hidden border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-emerald-500/25 hover:shadow-md hover:shadow-emerald-500/[0.02]">
                        <div className="absolute -top-12 -right-12 h-24 w-24 rounded-full bg-emerald-500/5 blur-2xl transition-all duration-500 group-hover:scale-125" />
                        <CardContent className="flex items-center justify-between p-5">
                            <div className="space-y-1">
                                <span className="text-[10px] font-bold tracking-wider text-emerald-500/70 uppercase dark:text-emerald-400/80">
                                    Completed Runs
                                </span>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                        {stats.completed_count}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        / {stats.history_count} total
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-3 text-emerald-500 transition-all duration-300 group-hover:bg-emerald-500/20">
                                <CheckCircle2 className="size-5" />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Avg Duration */}
                    <Card className="group relative overflow-hidden border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-blue-500/25 hover:shadow-md hover:shadow-blue-500/[0.02]">
                        <div className="absolute -top-12 -right-12 h-24 w-24 rounded-full bg-blue-500/5 blur-2xl transition-all duration-500 group-hover:scale-125" />
                        <CardContent className="flex items-center justify-between p-5">
                            <div className="space-y-1">
                                <span className="text-[10px] font-bold tracking-wider text-blue-500/70 uppercase dark:text-blue-400/80">
                                    Avg Duration
                                </span>
                                <div className="flex items-baseline gap-1">
                                    <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                        {formatDuration(stats.avg_duration_ms)}
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-xl border border-blue-500/20 bg-blue-500/10 p-3 text-blue-500 transition-all duration-300 group-hover:bg-blue-500/20">
                                <Timer className="size-5" />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Pending Jobs */}
                    <Card className="group relative overflow-hidden border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-amber-500/25 hover:shadow-md hover:shadow-amber-500/[0.02]">
                        <div className="absolute -top-12 -right-12 h-24 w-24 rounded-full bg-amber-500/5 blur-2xl transition-all duration-500 group-hover:scale-125" />
                        <CardContent className="flex items-center justify-between p-5">
                            <div className="space-y-1">
                                <span className="text-[10px] font-bold tracking-wider text-amber-500/70 uppercase dark:text-amber-400/80">
                                    Pending Jobs
                                </span>
                                <div className="flex items-baseline gap-1">
                                    <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                        {stats.pending_count}
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-amber-500 transition-all duration-300 group-hover:bg-amber-500/20">
                                <Hourglass className="size-5" />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Failed Jobs */}
                    <Card className="group relative overflow-hidden border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-rose-500/25 hover:shadow-md hover:shadow-rose-500/[0.02]">
                        <div className="absolute -top-12 -right-12 h-24 w-24 rounded-full bg-rose-500/5 blur-2xl transition-all duration-500 group-hover:scale-125" />
                        <CardContent className="flex items-center justify-between p-5">
                            <div className="space-y-1">
                                <span className="text-[10px] font-bold tracking-wider text-rose-500/70 uppercase dark:text-rose-400/80">
                                    Failed Jobs
                                </span>
                                <div className="flex items-baseline gap-1">
                                    <span className="text-3xl font-extrabold tracking-tight text-foreground">
                                        {stats.failed_count}
                                    </span>
                                </div>
                            </div>
                            <div className="rounded-xl border border-rose-500/20 bg-rose-500/10 p-3 text-rose-500 transition-all duration-300 group-hover:bg-rose-500/20">
                                <AlertTriangle className="size-5" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="mb-6 flex flex-wrap gap-2">
                    <button
                        type="button"
                        className={tabButtonClass(tab === 'history')}
                        onClick={() => switchTab('history')}
                    >
                        History
                    </button>
                    <button
                        type="button"
                        className={tabButtonClass(tab === 'failed')}
                        onClick={() => switchTab('failed')}
                    >
                        Failed
                    </button>
                    <button
                        type="button"
                        className={tabButtonClass(tab === 'pending')}
                        onClick={() => switchTab('pending')}
                    >
                        Pending
                    </button>
                    <button
                        type="button"
                        className={tabButtonClass(tab === 'registry')}
                        onClick={() => switchTab('registry')}
                    >
                        Registry
                    </button>
                </div>

                <Card className="mb-6 border-border/40 bg-card/45 backdrop-blur-md">
                    <CardContent className="grid gap-4 p-4 md:grid-cols-4">
                        <div className="space-y-1.5 md:col-span-2">
                            <label className="text-xs font-medium text-muted-foreground">
                                Search
                            </label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground/60" />
                                <Input
                                    value={list.searchInput}
                                    onChange={(event) =>
                                        list.onSearchChange(event.target.value)
                                    }
                                    placeholder={
                                        tab === 'history'
                                            ? 'Search name, message, exception, or correlation ID...'
                                            : tab === 'registry'
                                              ? 'Search name, class, signature, or purpose...'
                                              : 'Search queue, payload, or exception...'
                                    }
                                    className="h-10 rounded-xl border-border/40 bg-background/45 pl-9 transition-all duration-200 focus:border-primary/50"
                                />
                            </div>
                        </div>

                        {tab === 'history' ? (
                            <>
                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">
                                        Status
                                    </label>
                                    <AppSelect
                                        value={filters.status || 'all'}
                                        onValueChange={(value) =>
                                            submitFilters({
                                                status:
                                                    value === 'all'
                                                        ? ''
                                                        : value,
                                            })
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="all">
                                            All statuses
                                        </AppSelectItem>
                                        {statuses.map((status) => (
                                            <AppSelectItem
                                                key={status}
                                                value={status}
                                            >
                                                {status}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">
                                        Job / command
                                    </label>
                                    <AppSelect
                                        value={filters.name || 'all'}
                                        onValueChange={(value) =>
                                            submitFilters({
                                                name:
                                                    value === 'all'
                                                        ? ''
                                                        : value,
                                            })
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="all">
                                            All jobs
                                        </AppSelectItem>
                                        {names.map((name) => (
                                            <AppSelectItem
                                                key={name}
                                                value={name}
                                            >
                                                {name}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">
                                        From date
                                    </label>
                                    <Input
                                        type="date"
                                        value={filters.date_from}
                                        onChange={(event) =>
                                            submitFilters({
                                                date_from: event.target.value,
                                            })
                                        }
                                        className="h-10 rounded-xl border-border/40 bg-background/45 transition-all duration-200"
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">
                                        To date
                                    </label>
                                    <Input
                                        type="date"
                                        value={filters.date_to}
                                        onChange={(event) =>
                                            submitFilters({
                                                date_to: event.target.value,
                                            })
                                        }
                                        className="h-10 rounded-xl border-border/40 bg-background/45 transition-all duration-200"
                                    />
                                </div>
                            </>
                        ) : null}
                    </CardContent>
                </Card>

                <div className="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                    <span>
                        {tab === 'registry'
                            ? `${filteredRegistry.length} jobs & commands documented`
                            : `${pagination.total} total · showing ${rowsCount} on this page`}
                        {activeFilterCount > 0
                            ? ` · ${activeFilterCount} filter(s) active`
                            : ''}
                    </span>

                    {tab === 'failed' && failed_jobs.length > 0 && (
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 border-amber-500/20 text-amber-500 transition-colors hover:bg-amber-500/10"
                                disabled={isRetryingAll}
                                onClick={retryAllFailed}
                            >
                                <RotateCcw className="mr-1 size-3.5" />
                                Retry all failed
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                className="h-8 shadow-sm transition-colors"
                                onClick={() => setShowDeleteAllConfirm(true)}
                            >
                                <Trash2 className="mr-1 size-3.5" />
                                Delete all failed
                            </Button>
                        </div>
                    )}

                    {tab === 'history' && stats.history_count > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            className="h-8 shadow-sm transition-colors"
                            onClick={() => setShowDeleteAllHistoryConfirm(true)}
                        >
                            <Trash2 className="mr-1 size-3.5" />
                            Clear all history
                        </Button>
                    )}

                    {tab === 'pending' && pending_jobs.length > 0 && (
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            className="h-8 shadow-sm transition-colors"
                            onClick={() => setShowDeleteAllPendingConfirm(true)}
                        >
                            <Trash2 className="mr-1 size-3.5" />
                            Delete all pending
                        </Button>
                    )}
                </div>

                {tab === 'history' ? (
                    <div className="space-y-3">
                        {history_runs.length === 0 ? (
                            <Card className="border-border/40 bg-card/45 backdrop-blur-md">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No job runs recorded yet.
                                </CardContent>
                            </Card>
                        ) : (
                            history_runs.map((run) => {
                                const rowId = `history-${run.id}`;
                                const isOpen = openId === rowId;

                                return (
                                    <Collapsible
                                        key={run.id}
                                        open={isOpen}
                                        onOpenChange={(open) =>
                                            setOpenId(open ? rowId : null)
                                        }
                                    >
                                        <Card className="border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-primary/20 hover:shadow-md hover:shadow-primary/[0.01]">
                                            <CollapsibleTrigger className="w-full text-left">
                                                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-foreground">
                                                                {run.name}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    statusStyle(
                                                                        run.status,
                                                                    ),
                                                                    'gap-1 px-2.5 py-0.5',
                                                                )}
                                                            >
                                                                {run.status ===
                                                                    'running' && (
                                                                    <span className="relative flex h-1.5 w-1.5">
                                                                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                                                                        <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                                    </span>
                                                                )}
                                                                {run.status}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 px-2 py-0.5 text-muted-foreground/80"
                                                            >
                                                                {run.type}
                                                            </Badge>
                                                            {run.trigger ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-border/60 px-2 py-0.5 text-muted-foreground/80"
                                                                >
                                                                    {
                                                                        run.trigger
                                                                    }
                                                                </Badge>
                                                            ) : null}

                                                            {run.correlation_id && (
                                                                <div className="flex items-center gap-1 rounded border border-border/40 bg-muted/40 px-2 py-0.5 font-mono text-[10px] text-muted-foreground/80">
                                                                    <span>
                                                                        id:
                                                                    </span>
                                                                    <span className="max-w-[120px] truncate">
                                                                        {
                                                                            run.correlation_id
                                                                        }
                                                                    </span>
                                                                    <CopyButton
                                                                        text={
                                                                            run.correlation_id
                                                                        }
                                                                    />
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                            <span>
                                                                Started{' '}
                                                                {run.started_at
                                                                    ? formatDateTime(
                                                                          run.started_at,
                                                                      )
                                                                    : '—'}
                                                            </span>
                                                            {run.finished_at && (
                                                                <span>
                                                                    · Finished{' '}
                                                                    {formatDateTime(
                                                                        run.finished_at,
                                                                    )}
                                                                </span>
                                                            )}
                                                            {run.duration_ms !==
                                                                null && (
                                                                <span className="inline-flex items-center gap-1 font-medium text-muted-foreground/90">
                                                                    <Timer className="size-3.5 text-primary/70" />
                                                                    {formatDuration(
                                                                        run.duration_ms,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                        {run.message ? (
                                                            <p className="rounded-lg border border-border/20 bg-muted/20 px-3 py-1.5 text-sm text-muted-foreground">
                                                                {run.message}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                    <div className="flex items-center gap-2 self-start sm:self-center">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 border-red-500/30 text-red-500 transition-colors hover:bg-red-500/10"
                                                            onClick={(
                                                                event,
                                                            ) => {
                                                                event.stopPropagation();
                                                                setDeleteHistoryId(
                                                                    run.id,
                                                                );
                                                            }}
                                                        >
                                                            <Trash2 className="mr-1 size-3.5" />
                                                            Delete
                                                        </Button>
                                                        <Clock3 className="size-4 shrink-0 text-muted-foreground/40 transition-transform duration-200" />
                                                    </div>
                                                </CardContent>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent>
                                                <CardContent className="space-y-4 border-t border-border/40 p-4 text-xs">
                                                    {run.status ===
                                                        'completed' && (
                                                        <div className="space-y-2 rounded-lg border border-emerald-500/10 bg-emerald-500/[0.03] p-3 dark:bg-emerald-500/[0.02]">
                                                            <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                                                <CheckCircle2 className="size-3.5" />
                                                                <span>
                                                                    Execution
                                                                    Result &
                                                                    Changes Done
                                                                </span>
                                                            </div>
                                                            <div className="space-y-2 pl-5 text-xs text-muted-foreground">
                                                                <p className="font-medium text-foreground">
                                                                    {run.message ||
                                                                        'Job completed successfully.'}
                                                                </p>
                                                                {run.context &&
                                                                    Object.keys(
                                                                        run.context,
                                                                    ).length >
                                                                        0 && (
                                                                        <div className="mt-2 grid max-w-lg grid-cols-2 gap-3 border-t border-emerald-500/10 pt-2">
                                                                            {Object.entries(
                                                                                run.context,
                                                                            ).map(
                                                                                ([
                                                                                    key,
                                                                                    value,
                                                                                ]) => {
                                                                                    if (
                                                                                        value ===
                                                                                            null ||
                                                                                        value ===
                                                                                            undefined
                                                                                    ) {
                                                                                        return null;
                                                                                    }

                                                                                    const formattedKey =
                                                                                        key.replace(
                                                                                            /_/g,
                                                                                            ' ',
                                                                                        );

                                                                                    return (
                                                                                        <div
                                                                                            key={
                                                                                                key
                                                                                            }
                                                                                            className="flex flex-col"
                                                                                        >
                                                                                            <span className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                                                                                                {
                                                                                                    formattedKey
                                                                                                }
                                                                                            </span>
                                                                                            <span className="mt-0.5 font-mono text-foreground">
                                                                                                {typeof value ===
                                                                                                'object'
                                                                                                    ? JSON.stringify(
                                                                                                          value,
                                                                                                      )
                                                                                                    : String(
                                                                                                          value,
                                                                                                      )}
                                                                                            </span>
                                                                                        </div>
                                                                                    );
                                                                                },
                                                                            )}
                                                                        </div>
                                                                    )}
                                                            </div>
                                                        </div>
                                                    )}

                                                    {run.context ? (
                                                        <div className="space-y-1.5">
                                                            <div className="flex items-center justify-between text-xs font-semibold text-muted-foreground">
                                                                <span>
                                                                    Context
                                                                    Payload
                                                                    (Technical
                                                                    Details)
                                                                </span>
                                                                <CopyButton
                                                                    text={JSON.stringify(
                                                                        run.context,
                                                                        null,
                                                                        2,
                                                                    )}
                                                                />
                                                            </div>
                                                            <pre className="overflow-x-auto rounded-lg border border-border/30 bg-muted/30 p-3 font-mono text-[11px] leading-relaxed">
                                                                {JSON.stringify(
                                                                    run.context,
                                                                    null,
                                                                    2,
                                                                )}
                                                            </pre>
                                                        </div>
                                                    ) : null}
                                                    {run.exception ? (
                                                        <div className="space-y-1.5">
                                                            <div className="flex items-center justify-between text-xs font-semibold text-rose-500/80">
                                                                <span>
                                                                    Exception
                                                                    Details
                                                                </span>
                                                                <CopyButton
                                                                    text={
                                                                        run.exception
                                                                    }
                                                                />
                                                            </div>
                                                            <pre className="overflow-x-auto rounded-lg border border-rose-500/10 bg-rose-500/5 p-3 font-mono text-[11px] leading-relaxed text-rose-500">
                                                                {run.exception}
                                                            </pre>
                                                        </div>
                                                    ) : null}
                                                </CardContent>
                                            </CollapsibleContent>
                                        </Card>
                                    </Collapsible>
                                );
                            })
                        )}
                    </div>
                ) : null}

                {tab === 'failed' ? (
                    <div className="space-y-3">
                        {failed_jobs.length === 0 ? (
                            <Card className="border-border/40 bg-card/45 backdrop-blur-md">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No failed queue jobs.
                                </CardContent>
                            </Card>
                        ) : (
                            failed_jobs.map((job) => {
                                const rowId = `failed-${job.uuid}`;
                                const isOpen = openId === rowId;

                                return (
                                    <Collapsible
                                        key={job.uuid}
                                        open={isOpen}
                                        onOpenChange={(open) =>
                                            setOpenId(open ? rowId : null)
                                        }
                                    >
                                        <Card className="border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-rose-500/20 hover:shadow-md hover:shadow-rose-500/[0.01]">
                                            <CardContent className="space-y-4 p-4">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-foreground">
                                                                {job.name}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    statusStyle(
                                                                        'failed',
                                                                    ),
                                                                    'px-2.5 py-0.5',
                                                                )}
                                                            >
                                                                failed
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 px-2 py-0.5 font-mono text-muted-foreground/80"
                                                            >
                                                                queue:{' '}
                                                                {job.queue}
                                                            </Badge>
                                                            {job.connection && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-border/60 px-2 py-0.5 text-muted-foreground/80"
                                                                >
                                                                    connection:{' '}
                                                                    {
                                                                        job.connection
                                                                    }
                                                                </Badge>
                                                            )}
                                                            <div className="flex items-center gap-1 rounded border border-border/40 bg-muted/40 px-2 py-0.5 font-mono text-[10px] text-muted-foreground/80">
                                                                <span>
                                                                    uuid:
                                                                </span>
                                                                <span className="max-w-[120px] truncate">
                                                                    {job.uuid}
                                                                </span>
                                                                <CopyButton
                                                                    text={
                                                                        job.uuid
                                                                    }
                                                                />
                                                            </div>
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            Failed{' '}
                                                            {formatDateTime(
                                                                job.failed_at,
                                                            )}
                                                        </div>
                                                        <p className="rounded-lg border border-rose-500/10 bg-rose-500/5 px-3 py-1.5 text-sm font-medium text-rose-500/90">
                                                            {
                                                                job.exception_summary
                                                            }
                                                        </p>
                                                    </div>
                                                    <div className="flex gap-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 transition-colors"
                                                            disabled={
                                                                isRetrying
                                                            }
                                                            onClick={() =>
                                                                retryFailed(
                                                                    job.uuid,
                                                                )
                                                            }
                                                        >
                                                            <RotateCcw className="mr-1 size-3.5" />
                                                            Retry
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 border-red-500/30 text-red-500 transition-colors hover:bg-red-500/10"
                                                            onClick={() =>
                                                                setDeleteUuid(
                                                                    job.uuid,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="mr-1 size-3.5" />
                                                            Delete
                                                        </Button>
                                                    </div>
                                                </div>
                                                <Collapsible
                                                    open={isOpen}
                                                    onOpenChange={(open) =>
                                                        setOpenId(
                                                            open ? rowId : null,
                                                        )
                                                    }
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <CollapsibleTrigger className="text-xs font-medium text-primary hover:underline">
                                                            {isOpen
                                                                ? 'Hide details'
                                                                : 'Show details'}
                                                        </CollapsibleTrigger>
                                                    </div>
                                                    <CollapsibleContent className="space-y-4 pt-3">
                                                        {job.payload ? (
                                                            <div className="space-y-1.5">
                                                                <div className="flex items-center justify-between text-xs font-semibold text-muted-foreground">
                                                                    <span>
                                                                        Job
                                                                        Payload
                                                                    </span>
                                                                    <CopyButton
                                                                        text={JSON.stringify(
                                                                            job.payload,
                                                                            null,
                                                                            2,
                                                                        )}
                                                                    />
                                                                </div>
                                                                <pre className="overflow-x-auto rounded-lg border border-border/30 bg-muted/30 p-3 font-mono text-[11px] leading-relaxed">
                                                                    {JSON.stringify(
                                                                        job.payload,
                                                                        null,
                                                                        2,
                                                                    )}
                                                                </pre>
                                                            </div>
                                                        ) : null}

                                                        <div className="space-y-1.5">
                                                            <div className="flex items-center justify-between text-xs font-semibold text-rose-500/80">
                                                                <span>
                                                                    Exception
                                                                    Stack Trace
                                                                </span>
                                                                <CopyButton
                                                                    text={
                                                                        job.exception
                                                                    }
                                                                />
                                                            </div>
                                                            <pre className="overflow-x-auto rounded-lg border border-rose-500/10 bg-rose-500/5 p-3 font-mono text-[11px] leading-relaxed text-rose-500">
                                                                {job.exception}
                                                            </pre>
                                                        </div>
                                                    </CollapsibleContent>
                                                </Collapsible>
                                            </CardContent>
                                        </Card>
                                    </Collapsible>
                                );
                            })
                        )}
                    </div>
                ) : null}

                {tab === 'pending' ? (
                    <div className="space-y-3">
                        {pending_jobs.length === 0 ? (
                            <Card className="border-border/40 bg-card/45 backdrop-blur-md">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No pending queue jobs.
                                </CardContent>
                            </Card>
                        ) : (
                            pending_jobs.map((job) => {
                                const rowId = `pending-${job.id}`;
                                const isOpen = openId === rowId;

                                return (
                                    <Collapsible
                                        key={job.id}
                                        open={isOpen}
                                        onOpenChange={(open) =>
                                            setOpenId(open ? rowId : null)
                                        }
                                    >
                                        <Card className="border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-primary/20 hover:shadow-md hover:shadow-primary/[0.01]">
                                            <CardContent className="space-y-3 p-4">
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="space-y-1.5">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-foreground">
                                                                {job.name}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    statusStyle(
                                                                        job.reserved_at
                                                                            ? 'running'
                                                                            : 'completed',
                                                                    ),
                                                                    'px-2.5 py-0.5',
                                                                )}
                                                            >
                                                                {job.reserved_at
                                                                    ? 'reserved'
                                                                    : 'waiting'}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 px-2 py-0.5 font-mono text-muted-foreground/80"
                                                            >
                                                                queue:{' '}
                                                                {job.queue}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 px-2 py-0.5 text-muted-foreground/80"
                                                            >
                                                                attempts:{' '}
                                                                {job.attempts}
                                                            </Badge>
                                                        </div>
                                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                            <span>
                                                                Created{' '}
                                                                {formatDateTime(
                                                                    job.created_at,
                                                                )}
                                                            </span>
                                                            <span>
                                                                · Available{' '}
                                                                {formatDateTime(
                                                                    job.available_at,
                                                                )}
                                                            </span>
                                                            {job.reserved_at && (
                                                                <span className="text-amber-500">
                                                                    · Reserved{' '}
                                                                    {formatDateTime(
                                                                        job.reserved_at,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2 self-start sm:self-center">
                                                        {job.payload && (
                                                            <CollapsibleTrigger className="text-xs font-medium text-primary hover:underline">
                                                                {isOpen
                                                                    ? 'Hide payload'
                                                                    : 'Show payload'}
                                                            </CollapsibleTrigger>
                                                        )}
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 border-red-500/30 text-red-500 transition-colors hover:bg-red-500/10"
                                                            onClick={() =>
                                                                setDeletePendingId(
                                                                    job.id,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="mr-1 size-3.5" />
                                                            Delete
                                                        </Button>
                                                    </div>
                                                </div>
                                                {job.payload && (
                                                    <CollapsibleContent className="pt-2">
                                                        <div className="space-y-1.5">
                                                            <div className="flex items-center justify-between text-xs font-semibold text-muted-foreground">
                                                                <span>
                                                                    Job Payload
                                                                </span>
                                                                <CopyButton
                                                                    text={JSON.stringify(
                                                                        job.payload,
                                                                        null,
                                                                        2,
                                                                    )}
                                                                />
                                                            </div>
                                                            <pre className="overflow-x-auto rounded-lg border border-border/30 bg-muted/30 p-3 font-mono text-[11px] leading-relaxed">
                                                                {JSON.stringify(
                                                                    job.payload,
                                                                    null,
                                                                    2,
                                                                )}
                                                            </pre>
                                                        </div>
                                                    </CollapsibleContent>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </Collapsible>
                                );
                            })
                        )}
                    </div>
                ) : null}

                {/* Registry Tab content */}
                {tab === 'registry' ? (
                    <div className="space-y-8">
                        {/* Queueable Jobs Directory */}
                        <div className="space-y-4">
                            <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                <Workflow className="size-5 text-primary" />
                                <h2 className="text-lg font-bold text-foreground">
                                    Queueable Jobs
                                </h2>
                                <span className="text-xs text-muted-foreground">
                                    ({jobsList.length} items)
                                </span>
                            </div>

                            {jobsList.length === 0 ? (
                                <p className="text-sm text-muted-foreground italic">
                                    No matching queueable jobs found.
                                </p>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {jobsList.map((item) => (
                                        <Card
                                            key={item.name}
                                            className="flex flex-col justify-between border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-primary/20"
                                        >
                                            <CardContent className="flex-grow space-y-3 p-4">
                                                <div>
                                                    <h3 className="text-base font-bold text-foreground">
                                                        {item.name}
                                                    </h3>
                                                    <div className="mt-1 flex items-center gap-1.5 overflow-x-auto rounded border border-border/30 bg-muted/40 p-1.5 font-mono text-[10px] text-muted-foreground">
                                                        <span className="text-primary/70">
                                                            class:
                                                        </span>
                                                        <span className="truncate">
                                                            {item.class}
                                                        </span>
                                                        <div className="ml-auto">
                                                            <CopyButton
                                                                text={
                                                                    item.class
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="space-y-2 text-xs">
                                                    <div className="space-y-0.5">
                                                        <span className="flex items-center gap-1 font-semibold text-muted-foreground">
                                                            <Info className="size-3 text-primary/70" />{' '}
                                                            Purpose & Usage
                                                        </span>
                                                        <p className="leading-relaxed text-muted-foreground">
                                                            {item.purpose}
                                                        </p>
                                                    </div>

                                                    <div className="space-y-0.5">
                                                        <span className="font-semibold text-muted-foreground">
                                                            Dispatched by
                                                        </span>
                                                        <p className="leading-relaxed text-muted-foreground">
                                                            {item.trigger}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-wrap gap-2 pt-1">
                                                        {item.queue && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 bg-muted/20 font-mono"
                                                            >
                                                                queue:{' '}
                                                                {item.queue}
                                                            </Badge>
                                                        )}
                                                        {item.connection && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 bg-muted/20 font-mono"
                                                            >
                                                                connection:{' '}
                                                                {
                                                                    item.connection
                                                                }
                                                            </Badge>
                                                        )}
                                                    </div>

                                                    {item.details && (
                                                        <p className="rounded-lg border border-border/20 bg-muted/20 p-2 text-[11px] text-muted-foreground/80 italic">
                                                            {item.details}
                                                        </p>
                                                    )}
                                                </div>
                                            </CardContent>

                                            {/* Code Execution Snippet */}
                                            <div className="space-y-1 rounded-b-xl border-t border-border/30 bg-muted/20 p-3">
                                                <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                    <span>
                                                        How to dispatch in PHP
                                                    </span>
                                                    <CopyButton
                                                        text={item.code_snippet}
                                                    />
                                                </div>
                                                <pre className="overflow-x-auto rounded border border-border/40 bg-background/50 p-2 font-mono text-[11px] leading-normal text-foreground">
                                                    {item.code_snippet}
                                                </pre>
                                            </div>
                                        </Card>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Scheduled artisan commands */}
                        <div className="space-y-4 pt-4">
                            <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                <Terminal className="size-5 text-primary" />
                                <h2 className="text-lg font-bold text-foreground">
                                    Scheduled & Artisan Commands
                                </h2>
                                <span className="text-xs text-muted-foreground">
                                    ({commandsList.length} items)
                                </span>
                            </div>

                            {commandsList.length === 0 ? (
                                <p className="text-sm text-muted-foreground italic">
                                    No matching commands found.
                                </p>
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {commandsList.map((item) => (
                                        <Card
                                            key={item.name}
                                            className="flex flex-col justify-between border-border/40 bg-card/45 backdrop-blur-md transition-all duration-300 hover:border-primary/20"
                                        >
                                            <CardContent className="flex-grow space-y-3 p-4">
                                                <div>
                                                    <h3 className="font-mono text-base font-bold text-foreground text-primary/95">
                                                        {item.name}
                                                    </h3>
                                                    <div className="mt-1 flex items-center gap-1.5 overflow-x-auto rounded border border-border/30 bg-muted/40 p-1.5 font-mono text-[10px] text-muted-foreground">
                                                        <span className="text-primary/70">
                                                            class:
                                                        </span>
                                                        <span className="truncate">
                                                            {item.class}
                                                        </span>
                                                        <div className="ml-auto">
                                                            <CopyButton
                                                                text={
                                                                    item.class
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="space-y-2 text-xs">
                                                    <div className="space-y-0.5">
                                                        <span className="flex items-center gap-1 font-semibold text-muted-foreground">
                                                            <Info className="size-3 text-primary/70" />{' '}
                                                            Purpose & Action
                                                        </span>
                                                        <p className="leading-relaxed text-muted-foreground">
                                                            {item.purpose}
                                                        </p>
                                                    </div>

                                                    <div className="space-y-0.5">
                                                        <span className="font-semibold text-muted-foreground">
                                                            Trigger / Run
                                                            Frequency
                                                        </span>
                                                        <p className="leading-relaxed text-muted-foreground">
                                                            {item.trigger}
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-wrap gap-2 pt-1">
                                                        {item.schedule && (
                                                            <Badge
                                                                variant="outline"
                                                                className="gap-1 border-amber-500/20 bg-amber-500/10 font-mono text-amber-500"
                                                            >
                                                                <Calendar className="size-3" />
                                                                {item.schedule}
                                                            </Badge>
                                                        )}
                                                        {item.signature && (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-border/60 bg-muted/20 font-mono text-muted-foreground/80"
                                                            >
                                                                sig:{' '}
                                                                {
                                                                    item.signature.split(
                                                                        ' ',
                                                                    )[0]
                                                                }
                                                            </Badge>
                                                        )}
                                                    </div>

                                                    {item.details && (
                                                        <p className="rounded-lg border border-border/20 bg-muted/20 p-2 text-[11px] text-muted-foreground/80 italic">
                                                            {item.details}
                                                        </p>
                                                    )}
                                                </div>
                                            </CardContent>

                                            {/* Code Execution Snippet */}
                                            <div className="space-y-1 rounded-b-xl border-t border-border/30 bg-muted/20 p-3">
                                                <div className="flex items-center justify-between text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                    <span>
                                                        How to run in Terminal
                                                    </span>
                                                    <CopyButton
                                                        text={item.code_snippet}
                                                    />
                                                </div>
                                                <pre className="overflow-x-auto rounded border border-border/40 bg-background/50 p-2 font-mono text-[11px] leading-normal text-foreground">
                                                    {item.code_snippet}
                                                </pre>
                                            </div>
                                        </Card>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ) : null}

                {/* Pagination */}
                {tab !== 'registry' && (
                    <div className="mt-8">
                        <Pagination {...list.paginationProps} label="jobs" />
                    </div>
                )}
            </Main>

            <ConfirmDeleteDialog
                open={deleteUuid !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteUuid(null);
                    }
                }}
                title="Delete failed job?"
                description="This removes the failed job record from the queue. It will not run again unless re-dispatched."
                onConfirm={deleteFailed}
            />

            <ConfirmDeleteDialog
                open={showDeleteAllConfirm}
                onOpenChange={setShowDeleteAllConfirm}
                title="Delete all failed jobs?"
                description="This removes all failed job records from the queue. They will not run again unless re-dispatched."
                onConfirm={deleteAllFailed}
            />

            <ConfirmDeleteDialog
                open={deleteHistoryId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteHistoryId(null);
                    }
                }}
                title="Delete job run history?"
                description="This removes the selected history record. It does not affect pending or failed queue jobs."
                onConfirm={deleteHistory}
            />

            <ConfirmDeleteDialog
                open={showDeleteAllHistoryConfirm}
                onOpenChange={setShowDeleteAllHistoryConfirm}
                title="Clear all job run history?"
                description="This removes every recorded job run from history. Pending and failed queue jobs are not affected."
                onConfirm={deleteAllHistory}
            />

            <ConfirmDeleteDialog
                open={deletePendingId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeletePendingId(null);
                    }
                }}
                title="Delete pending job?"
                description="This removes the job from the queue before it runs. It will not run unless dispatched again."
                onConfirm={deletePending}
            />

            <ConfirmDeleteDialog
                open={showDeleteAllPendingConfirm}
                onOpenChange={setShowDeleteAllPendingConfirm}
                title="Delete all pending jobs?"
                description="This removes every job waiting in the queue. None of them will run unless dispatched again."
                onConfirm={deleteAllPending}
            />
        </>
    );
}
