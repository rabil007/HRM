import { Head, useForm } from '@inertiajs/react';
import {
    Activity,
    Calendar,
    ChevronDown,
    ChevronUp,
    Filter,
    Search,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type AuditLog = {
    id: number;
    event: 'created' | 'updated' | 'deleted' | string;
    subject_type: string;
    subject_name: string;
    subject_id: number | null;
    subject_label: string | null;
    description: string | null;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip: string | null;
    created_at: string;
};

const HIDDEN_KEYS = new Set([
    'id',
    'company_id',
    'created_at',
    'updated_at',
    'deleted_at',
    'remember_token',
    'password',
]);

function pickChangedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((k) => !HIDDEN_KEYS.has(k))
        .sort((a, b) => a.localeCompare(b));
}

function titleCaseKey(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}

function eventStyle(event: string): {
    badge: string;
    dot: string;
    label: string;
} {
    switch (event) {
        case 'created':
            return {
                badge: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400',
                dot: 'bg-emerald-400',
                label: 'Created',
            };
        case 'deleted':
            return {
                badge: 'bg-red-500/10 text-red-600 border-red-500/20 dark:text-red-400',
                dot: 'bg-red-400',
                label: 'Deleted',
            };
        default:
            return {
                badge: 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400',
                dot: 'bg-sky-400',
                label: 'Updated',
            };
    }
}

/** Causer initials avatar */
function CauserAvatar({ name }: { name: string }) {
    const initials = name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

    return (
        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-gradient-to-br from-primary/30 to-primary/10">
            <span className="text-[9px] font-black text-primary">
                {initials}
            </span>
        </div>
    );
}

const EVENTS = ['created', 'updated', 'deleted'] as const;

export default function ActivityLogs({
    logs,
    pagination,
    filters,
    subject_types,
}: {
    logs: AuditLog[];
    pagination: PaginationMeta;
    filters: {
        q: string;
        event: string;
        subject: string;
        date_from: string;
        date_to: string;
    };
    subject_types: string[];
}) {
    const form = useForm({
        q: filters.q ?? '',
        event: filters.event ?? '',
        subject: filters.subject ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const [openId, setOpenId] = useState<number | null>(null);

    const list = useServerPaginationFilters({
        url: '/organization/activity-logs',
        search: filters.q ?? '',
        filters: {
            event: filters.event,
            subject: filters.subject,
            date_from: filters.date_from,
            date_to: filters.date_to,
        },
        searchKey: 'q',
        pagination,
    });

    const submit = (next?: Partial<typeof form.data>) => {
        const data = { ...form.data, ...(next ?? {}) };
        form.setData(data);
        list.visit({ ...data, page: null });
    };

    const resetFilters = () => {
        const data = {
            q: '',
            event: '',
            subject: '',
            date_from: '',
            date_to: '',
        };
        form.setData(data);
        list.visit({ ...data, page: null });
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (form.data.q) {
            count++;
        }

        if (form.data.event) {
            count++;
        }

        if (form.data.subject) {
            count++;
        }

        if (form.data.date_from) {
            count++;
        }

        if (form.data.date_to) {
            count++;
        }

        return count;
    }, [form.data]);

    return (
        <>
            <Head title="Activity logs" />

            <Main>
                {/* ── Page header ── */}
                <div className="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <div className="mb-1 flex items-center gap-2">
                            <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                Organization
                            </span>
                        </div>
                        <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                            Activity logs
                        </h1>
                        <p className="mt-1 text-sm font-medium text-muted-foreground/70">
                            Track every change across your organization data.
                        </p>
                    </div>

                    {/* Total counter */}
                    <div className="flex shrink-0 items-center gap-3 rounded-xl border border-border bg-muted/30 px-4 py-3 dark:border-white/5 dark:bg-white/[0.03]">
                        <div className="flex h-8 w-8 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                            <Activity className="h-4 w-4" />
                        </div>
                        <div>
                            <p className="text-[10px] leading-none font-bold tracking-widest text-muted-foreground/40 uppercase">
                                Total events
                            </p>
                            <p className="text-lg font-black text-foreground">
                                {pagination.total.toLocaleString()}
                            </p>
                        </div>
                    </div>
                </div>

                {/* ── Filter bar ── */}
                <Card className="mb-6 border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                    <CardContent className="p-5">
                        <div className="mb-4 flex items-center gap-3">
                            <Filter className="h-4 w-4 text-muted-foreground/50" />
                            <span className="text-xs font-bold tracking-widest text-muted-foreground/50 uppercase">
                                Filters
                            </span>
                            {activeFilterCount > 0 ? (
                                <Badge className="border-primary/20 bg-primary/10 px-2 text-[10px] font-bold text-primary">
                                    {activeFilterCount} active
                                </Badge>
                            ) : null}
                            {activeFilterCount > 0 ? (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground/50 transition-colors hover:text-foreground"
                                >
                                    <X className="h-3 w-3" />
                                    Clear all
                                </button>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-4">
                            {/* First row: main inputs */}
                            <div className="flex flex-col gap-3 lg:flex-row">
                                {/* Search */}
                                <div className="relative min-w-[240px] flex-1">
                                    <Search className="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                                    <Input
                                        id="q"
                                        value={form.data.q}
                                        onChange={(e) =>
                                            form.setData('q', e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                submit();
                                            }
                                        }}
                                        placeholder="Search subject, model, user…"
                                        className="h-10 rounded-xl border-border bg-muted/50 pl-10 focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                    />
                                </div>

                                {/* Model */}
                                <div className="w-full shrink-0 lg:w-48">
                                    <AppSelect
                                        value={form.data.subject || ''}
                                        onValueChange={(v) =>
                                            submit({ subject: v })
                                        }
                                        variant="dark"
                                        placeholder="All models"
                                        className="h-10"
                                    >
                                        <AppSelectItem value="">
                                            All models
                                        </AppSelectItem>
                                        {subject_types.map((t) => (
                                            <AppSelectItem key={t} value={t}>
                                                {t.split('\\').slice(-1)[0]}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                {/* Date Range */}
                                <div className="flex w-full shrink-0 items-center gap-2 lg:w-auto">
                                    <div className="relative flex-1 lg:w-36">
                                        <Calendar className="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground/40" />
                                        <Input
                                            id="date_from"
                                            type="date"
                                            value={form.data.date_from}
                                            onChange={(e) => {
                                                form.setData(
                                                    'date_from',
                                                    e.target.value,
                                                );
                                                submit({
                                                    date_from: e.target.value,
                                                });
                                            }}
                                            className="h-10 rounded-xl border-border bg-muted/50 pl-9 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                    <span className="shrink-0 text-xs text-muted-foreground/30 select-none">
                                        to
                                    </span>
                                    <div className="relative flex-1 lg:w-36">
                                        <Calendar className="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground/40" />
                                        <Input
                                            id="date_to"
                                            type="date"
                                            value={form.data.date_to}
                                            onChange={(e) => {
                                                form.setData(
                                                    'date_to',
                                                    e.target.value,
                                                );
                                                submit({
                                                    date_to: e.target.value,
                                                });
                                            }}
                                            className="h-10 rounded-xl border-border bg-muted/50 pl-9 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Second row: event pills and action buttons */}
                            <div className="flex flex-col justify-between gap-4 border-t border-border pt-3 sm:flex-row sm:items-center dark:border-white/5">
                                {/* Event filter pills */}
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="mr-1 text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase select-none">
                                        Event:
                                    </span>
                                    {(['all', ...EVENTS] as const).map((e) => {
                                        const isActive =
                                            (form.data.event || 'all') === e;

                                        return (
                                            <button
                                                key={e}
                                                type="button"
                                                onClick={() =>
                                                    submit({
                                                        event:
                                                            e === 'all'
                                                                ? ''
                                                                : e,
                                                    })
                                                }
                                                className={cn(
                                                    'h-7 rounded-full border px-3 text-[11px] font-bold tracking-wider uppercase transition-all',
                                                    isActive
                                                        ? e === 'all'
                                                            ? 'border-primary bg-primary text-primary-foreground shadow-sm shadow-primary/30'
                                                            : e === 'created'
                                                              ? 'border-emerald-500/30 bg-emerald-500/20 text-emerald-600 dark:text-emerald-400'
                                                              : e === 'deleted'
                                                                ? 'border-red-500/30 bg-red-500/20 text-red-600 dark:text-red-400'
                                                                : 'border-sky-500/30 bg-sky-500/20 text-sky-600 dark:text-sky-400'
                                                        : 'border-border bg-muted/30 text-muted-foreground/60 hover:border-border hover:text-foreground dark:border-white/5 dark:bg-white/[0.03] dark:hover:border-white/10',
                                                )}
                                            >
                                                {e}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* Apply button */}
                                <div className="flex justify-end gap-2">
                                    {activeFilterCount > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="h-9 rounded-xl text-xs text-muted-foreground/50 hover:text-foreground"
                                            onClick={resetFilters}
                                        >
                                            <X className="mr-1 h-3 w-3" />
                                            Clear filters
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        className="h-9 rounded-xl px-5 text-xs font-semibold"
                                        onClick={() => submit()}
                                    >
                                        Apply filters
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Log list ── */}
                <Card className="overflow-hidden border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                    {/* Table header */}
                    <div className="flex items-center justify-between border-b border-border bg-muted/20 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                        <h2 className="text-sm font-bold text-foreground/80">
                            Events
                        </h2>
                        <span className="font-mono text-[11px] text-muted-foreground/50">
                            {pagination.total.toLocaleString()} total
                        </span>
                    </div>

                    <CardContent className="p-0">
                        {logs.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-20 text-center">
                                <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                                    <Activity className="h-6 w-6 text-muted-foreground/20" />
                                </div>
                                <p className="text-sm font-medium text-foreground/50">
                                    No activity found
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground/40">
                                    Try adjusting your filters
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-border dark:divide-white/5">
                                {logs.map((log) => {
                                    const changedKeys = pickChangedKeys(
                                        log.old_values,
                                        log.new_values,
                                    );
                                    const previewKeys = changedKeys.slice(0, 3);
                                    const isOpen = openId === log.id;
                                    const style = eventStyle(log.event);
                                    const modelName = log.subject_name
                                        .split('\\')
                                        .slice(-1)[0];

                                    return (
                                        <Collapsible
                                            key={log.id}
                                            open={isOpen}
                                            onOpenChange={(v) =>
                                                setOpenId(v ? log.id : null)
                                            }
                                        >
                                            <CollapsibleTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="group w-full px-6 py-4 text-left transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.02]"
                                                >
                                                    <div className="grid w-full grid-cols-1 items-center gap-4 md:grid-cols-12">
                                                        {/* Col 1: Event & Model */}
                                                        <div className="flex min-w-0 items-center gap-3 md:col-span-4">
                                                            <div
                                                                className={cn(
                                                                    'h-2 w-2 shrink-0 rounded-full',
                                                                    style.dot,
                                                                )}
                                                            />
                                                            <Badge
                                                                className={cn(
                                                                    'shrink-0 border px-2 py-0.5 text-[9px] font-bold tracking-wider uppercase',
                                                                    style.badge,
                                                                )}
                                                            >
                                                                {log.event}
                                                            </Badge>
                                                            <div className="min-w-0 truncate">
                                                                <span className="block truncate text-sm font-bold text-foreground/90 md:inline">
                                                                    {modelName}
                                                                </span>
                                                                <span className="ml-1.5 shrink-0 font-mono text-xs text-muted-foreground/40">
                                                                    #
                                                                    {log.subject_id ??
                                                                        '—'}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        {/* Col 2: Changed fields preview / Subject label */}
                                                        <div className="flex min-w-0 flex-col gap-1 md:col-span-3">
                                                            {log.subject_label ? (
                                                                <span className="truncate text-xs font-semibold text-foreground/75">
                                                                    {
                                                                        log.subject_label
                                                                    }
                                                                </span>
                                                            ) : null}
                                                            {previewKeys.length >
                                                            0 ? (
                                                                <div className="flex flex-wrap items-center gap-1">
                                                                    {previewKeys.map(
                                                                        (k) => (
                                                                            <span
                                                                                key={
                                                                                    k
                                                                                }
                                                                                className="inline-flex items-center rounded-md border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] whitespace-nowrap text-muted-foreground/50 dark:border-white/5 dark:bg-white/[0.02]"
                                                                            >
                                                                                {titleCaseKey(
                                                                                    k,
                                                                                )}
                                                                            </span>
                                                                        ),
                                                                    )}
                                                                    {changedKeys.length >
                                                                    previewKeys.length ? (
                                                                        <span className="inline-flex items-center rounded-md border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] text-muted-foreground/35 dark:border-white/5 dark:bg-white/[0.02]">
                                                                            +
                                                                            {changedKeys.length -
                                                                                previewKeys.length}
                                                                        </span>
                                                                    ) : null}
                                                                </div>
                                                            ) : null}
                                                        </div>

                                                        {/* Col 3: Causer */}
                                                        <div className="flex min-w-0 items-center gap-2.5 md:col-span-3 md:justify-end">
                                                            {log.causer ? (
                                                                <>
                                                                    <div className="hidden min-w-0 text-right sm:block">
                                                                        <p className="truncate text-xs leading-none font-semibold text-foreground/80">
                                                                            {
                                                                                log
                                                                                    .causer
                                                                                    .name
                                                                            }
                                                                        </p>
                                                                        <p className="mt-1 truncate text-[9px] text-muted-foreground/40">
                                                                            {
                                                                                log
                                                                                    .causer
                                                                                    .email
                                                                            }
                                                                        </p>
                                                                    </div>
                                                                    <CauserAvatar
                                                                        name={
                                                                            log
                                                                                .causer
                                                                                .name
                                                                        }
                                                                    />
                                                                </>
                                                            ) : (
                                                                <span className="text-xs text-muted-foreground/40">
                                                                    System
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Col 4: Time & Action */}
                                                        <div className="flex shrink-0 items-center justify-end gap-3 md:col-span-2">
                                                            <span className="font-mono text-[10px] whitespace-nowrap text-muted-foreground/40">
                                                                {formatDisplayDate(
                                                                    log.created_at,
                                                                )}
                                                            </span>
                                                            {isOpen ? (
                                                                <ChevronUp className="h-3.5 w-3.5 shrink-0 text-muted-foreground/40" />
                                                            ) : (
                                                                <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground/30 transition-colors group-hover:text-muted-foreground/60" />
                                                            )}
                                                        </div>
                                                    </div>
                                                </button>
                                            </CollapsibleTrigger>

                                            {/* Expanded detail */}
                                            <CollapsibleContent>
                                                <div className="ml-[2.125rem] border-l-2 border-border px-6 pb-5 dark:border-white/5">
                                                    {changedKeys.length > 0 ? (
                                                        <div className="mt-2 overflow-hidden rounded-xl border border-border bg-muted/20 dark:border-white/5 dark:bg-white/[0.02]">
                                                            <div className="overflow-x-auto">
                                                                <table className="w-full border-collapse text-left text-xs">
                                                                    <thead>
                                                                        <tr className="border-b border-border bg-muted/20 text-[10px] font-bold tracking-wider text-muted-foreground/60 uppercase select-none dark:border-white/5 dark:bg-white/[0.02]">
                                                                            <th className="w-1/3 px-4 py-2.5">
                                                                                Field
                                                                            </th>
                                                                            <th className="w-1/3 px-4 py-2.5">
                                                                                Old
                                                                                Value
                                                                            </th>
                                                                            <th className="w-1/3 px-4 py-2.5">
                                                                                New
                                                                                Value
                                                                            </th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody className="divide-y divide-border dark:divide-white/5">
                                                                        {changedKeys.map(
                                                                            (
                                                                                k,
                                                                            ) => (
                                                                                <tr
                                                                                    key={
                                                                                        k
                                                                                    }
                                                                                    className="transition-colors hover:bg-muted/20 dark:hover:bg-white/[0.01]"
                                                                                >
                                                                                    <td className="px-4 py-3 font-semibold whitespace-nowrap text-muted-foreground/75">
                                                                                        {titleCaseKey(
                                                                                            k,
                                                                                        )}
                                                                                    </td>
                                                                                    <td className="px-4 py-3 font-mono text-[11px] break-all text-muted-foreground/50 line-through">
                                                                                        {formatDisplayValue(
                                                                                            log
                                                                                                .old_values?.[
                                                                                                k
                                                                                            ],
                                                                                        )}
                                                                                    </td>
                                                                                    <td className="px-4 py-3 font-mono text-[11px] font-semibold break-all text-foreground/80">
                                                                                        {formatDisplayValue(
                                                                                            log
                                                                                                .new_values?.[
                                                                                                k
                                                                                            ],
                                                                                        )}
                                                                                    </td>
                                                                                </tr>
                                                                            ),
                                                                        )}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <p className="mt-1 py-3 text-xs text-muted-foreground/40">
                                                            No field-level diff
                                                            available for this
                                                            event.
                                                        </p>
                                                    )}

                                                    {/* Meta row */}
                                                    {(log.ip ??
                                                    log.description) ? (
                                                        <div className="mt-3 flex flex-wrap gap-4 pl-4">
                                                            {log.ip ? (
                                                                <span className="font-mono text-[10px] text-muted-foreground/40">
                                                                    IP: {log.ip}
                                                                </span>
                                                            ) : null}
                                                            {log.description &&
                                                            log.description
                                                                .trim()
                                                                .toLowerCase() !==
                                                                log.event
                                                                    .trim()
                                                                    .toLowerCase() ? (
                                                                <span className="text-[10px] text-muted-foreground/40">
                                                                    {
                                                                        log.description
                                                                    }
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </CollapsibleContent>
                                        </Collapsible>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="mt-6">
                    <Pagination {...list.paginationProps} label="logs" />
                </div>
            </Main>
        </>
    );
}
