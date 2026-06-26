import { Head, Link, router } from '@inertiajs/react';
import { Clock3, Database, RotateCcw, Search, Terminal, Trash2, Workflow } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    destroyFailed as destroyFailedJob,
    index as jobsIndex,
    retryFailed as retryFailedJob,
} from '@/actions/App/Http/Controllers/JobRunController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type Tab = 'history' | 'failed' | 'pending';

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

type Props = {
    tab: Tab;
    history_runs: HistoryRun[];
    failed_jobs: FailedJob[];
    pending_jobs: PendingJob[];
    pagination: PaginationMeta;
    names: string[];
    statuses: string[];
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
            return 'bg-red-500/10 text-red-500 border-red-500/20';
        case 'running':
            return 'bg-amber-500/10 text-amber-500 border-amber-500/20';
        case 'completed':
            return 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
        default:
            return 'bg-zinc-500/10 text-muted-foreground border-border/60';
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
        'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
        active
            ? 'bg-primary text-primary-foreground'
            : 'text-muted-foreground hover:bg-muted/40 hover:text-foreground',
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
    filters,
}: Props) {
    const [openId, setOpenId] = useState<string | null>(null);
    const [deleteUuid, setDeleteUuid] = useState<string | null>(null);
    const [isRetrying, setIsRetrying] = useState(false);

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

    const submitFilters = (overrides?: Partial<typeof filters & { tab?: Tab }>) => {
        list.visit({ ...overrides, page: null });
    };

    const switchTab = (nextTab: Tab) => {
        submitFilters({ tab: nextTab, status: '', name: '', q: '', date_from: '', date_to: '' });
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

    const deleteFailed = () => {
        if (!deleteUuid) {
            return;
        }

        router.delete(destroyFailedJob.url(deleteUuid), {
            preserveScroll: true,
            onFinish: () => setDeleteUuid(null),
        });
    };

    const rowsCount =
        tab === 'history'
            ? history_runs.length
            : tab === 'failed'
              ? failed_jobs.length
              : pending_jobs.length;

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
                        <h1 className="text-3xl font-extrabold tracking-tight text-foreground">Job runs</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Track queue history, failed jobs, and pending work.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild type="button" size="sm" variant="outline" className="h-9">
                            <Link href="/log">
                                <Terminal className="size-3.5" />
                                Application logs
                            </Link>
                        </Button>
                        <Button asChild type="button" size="sm" variant="outline" className="h-9">
                            <Link href="/mysql">
                                <Database className="size-3.5" />
                                MySQL viewer
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="mb-6 flex flex-wrap gap-2">
                    <button type="button" className={tabButtonClass(tab === 'history')} onClick={() => switchTab('history')}>
                        History
                    </button>
                    <button type="button" className={tabButtonClass(tab === 'failed')} onClick={() => switchTab('failed')}>
                        Failed
                    </button>
                    <button type="button" className={tabButtonClass(tab === 'pending')} onClick={() => switchTab('pending')}>
                        Pending
                    </button>
                </div>

                <Card className="mb-6 border-border/60 bg-card/50">
                    <CardContent className="grid gap-4 p-4 md:grid-cols-4">
                        <div className="space-y-1.5 md:col-span-2">
                            <label className="text-xs font-medium text-muted-foreground">Search</label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground/60" />
                                <Input
                                    value={list.searchInput}
                                    onChange={(event) => list.onSearchChange(event.target.value)}
                                    placeholder={
                                        tab === 'history'
                                            ? 'Search name, message, or exception...'
                                            : 'Search queue, payload, or exception...'
                                    }
                                    className="h-10 rounded-xl border-border/60 bg-background/50 pl-9"
                                />
                            </div>
                        </div>

                        {tab === 'history' ? (
                            <>
                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">Status</label>
                                    <AppSelect
                                        value={filters.status || 'all'}
                                        onValueChange={(value) =>
                                            submitFilters({ status: value === 'all' ? '' : value })
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="all">All statuses</AppSelectItem>
                                        {statuses.map((status) => (
                                            <AppSelectItem key={status} value={status}>
                                                {status}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">Job / command</label>
                                    <AppSelect
                                        value={filters.name || 'all'}
                                        onValueChange={(value) =>
                                            submitFilters({ name: value === 'all' ? '' : value })
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="all">All jobs</AppSelectItem>
                                        {names.map((name) => (
                                            <AppSelectItem key={name} value={name}>
                                                {name}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">From date</label>
                                    <Input
                                        type="date"
                                        value={filters.date_from}
                                        onChange={(event) => submitFilters({ date_from: event.target.value })}
                                        className="h-10 rounded-xl border-border/60 bg-background/50"
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <label className="text-xs font-medium text-muted-foreground">To date</label>
                                    <Input
                                        type="date"
                                        value={filters.date_to}
                                        onChange={(event) => submitFilters({ date_to: event.target.value })}
                                        className="h-10 rounded-xl border-border/60 bg-background/50"
                                    />
                                </div>
                            </>
                        ) : null}
                    </CardContent>
                </Card>

                <div className="mb-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>
                        {pagination.total} total · showing {rowsCount} on this page
                        {activeFilterCount > 0 ? ` · ${activeFilterCount} filter(s) active` : ''}
                    </span>
                </div>

                {tab === 'history' ? (
                    <div className="space-y-3">
                        {history_runs.length === 0 ? (
                            <Card className="border-border/60 bg-card/50">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No job runs recorded yet.
                                </CardContent>
                            </Card>
                        ) : (
                            history_runs.map((run) => {
                                const rowId = `history-${run.id}`;
                                const isOpen = openId === rowId;

                                return (
                                    <Collapsible key={run.id} open={isOpen} onOpenChange={(open) => setOpenId(open ? rowId : null)}>
                                        <Card className="border-border/60 bg-card/50">
                                            <CollapsibleTrigger className="w-full text-left">
                                                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                                    <div className="space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-foreground">{run.name}</span>
                                                            <Badge variant="outline" className={statusStyle(run.status)}>
                                                                {run.status}
                                                            </Badge>
                                                            <Badge variant="outline">{run.type}</Badge>
                                                            {run.trigger ? (
                                                                <Badge variant="outline">{run.trigger}</Badge>
                                                            ) : null}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            Started {run.started_at ? formatDisplayDateTime(run.started_at) : '—'}
                                                            {run.finished_at
                                                                ? ` · Finished ${formatDisplayDateTime(run.finished_at)}`
                                                                : ''}
                                                            {run.duration_ms !== null ? ` · ${formatDuration(run.duration_ms)}` : ''}
                                                        </div>
                                                        {run.message ? (
                                                            <p className="text-sm text-muted-foreground">{run.message}</p>
                                                        ) : null}
                                                    </div>
                                                    <Clock3 className="size-4 shrink-0 text-muted-foreground/50" />
                                                </CardContent>
                                            </CollapsibleTrigger>
                                            <CollapsibleContent>
                                                <CardContent className="space-y-3 border-t border-border/60 p-4 text-xs">
                                                    {run.context ? (
                                                        <pre className="overflow-x-auto rounded-lg bg-muted/30 p-3 text-[11px]">
                                                            {JSON.stringify(run.context, null, 2)}
                                                        </pre>
                                                    ) : null}
                                                    {run.exception ? (
                                                        <pre className="overflow-x-auto rounded-lg bg-red-500/5 p-3 text-[11px] text-red-500">
                                                            {run.exception}
                                                        </pre>
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
                            <Card className="border-border/60 bg-card/50">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No failed queue jobs.
                                </CardContent>
                            </Card>
                        ) : (
                            failed_jobs.map((job) => {
                                const rowId = `failed-${job.uuid}`;
                                const isOpen = openId === rowId;

                                return (
                                    <Collapsible key={job.uuid} open={isOpen} onOpenChange={(open) => setOpenId(open ? rowId : null)}>
                                        <Card className="border-border/60 bg-card/50">
                                            <CardContent className="space-y-4 p-4">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold text-foreground">{job.name}</span>
                                                            <Badge variant="outline" className={statusStyle('failed')}>
                                                                failed
                                                            </Badge>
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {formatDisplayDateTime(job.failed_at)} · queue {job.queue}
                                                        </div>
                                                        <p className="text-sm text-muted-foreground">{job.exception_summary}</p>
                                                    </div>
                                                    <div className="flex gap-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8"
                                                            disabled={isRetrying}
                                                            onClick={() => retryFailed(job.uuid)}
                                                        >
                                                            <RotateCcw className="size-3.5" />
                                                            Retry
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            className="h-8 border-red-500/30 text-red-500 hover:bg-red-500/10"
                                                            onClick={() => setDeleteUuid(job.uuid)}
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                            Delete
                                                        </Button>
                                                    </div>
                                                </div>
                                                <Collapsible open={isOpen} onOpenChange={(open) => setOpenId(open ? rowId : null)}>
                                                    <CollapsibleTrigger className="text-xs font-medium text-primary">
                                                        {isOpen ? 'Hide details' : 'Show details'}
                                                    </CollapsibleTrigger>
                                                    <CollapsibleContent>
                                                        <pre className="mt-3 overflow-x-auto rounded-lg bg-red-500/5 p-3 text-[11px] text-red-500">
                                                            {job.exception}
                                                        </pre>
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
                            <Card className="border-border/60 bg-card/50">
                                <CardContent className="p-8 text-center text-sm text-muted-foreground">
                                    No pending queue jobs.
                                </CardContent>
                            </Card>
                        ) : (
                            pending_jobs.map((job) => (
                                <Card key={job.id} className="border-border/60 bg-card/50">
                                    <CardContent className="space-y-2 p-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-foreground">{job.name}</span>
                                            <Badge variant="outline" className={statusStyle(job.reserved_at ? 'running' : 'completed')}>
                                                {job.reserved_at ? 'reserved' : 'waiting'}
                                            </Badge>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Queue {job.queue} · attempts {job.attempts} · created{' '}
                                            {formatDisplayDateTime(job.created_at)}
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>
                ) : null}

                <div className="mt-8">
                    <Pagination pagination={pagination} onPageChange={list.onPageChange} />
                </div>
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
        </>
    );
}
