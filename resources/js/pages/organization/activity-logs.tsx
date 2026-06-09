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
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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
        <div className="w-7 h-7 rounded-full bg-gradient-to-br from-primary/30 to-primary/10 border border-primary/20 flex items-center justify-center shrink-0">
            <span className="text-[9px] font-black text-primary">{initials}</span>
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
    filters: { q: string; event: string; subject: string; date_from: string; date_to: string };
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
        const data = { q: '', event: '', subject: '', date_from: '', date_to: '' };
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
                <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
                    <div>
                        <div className="flex items-center gap-2 mb-1">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
                                Organization
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                            Activity logs
                        </h1>
                        <p className="text-sm text-muted-foreground/70 font-medium mt-1">
                            Track every change across your organization data.
                        </p>
                    </div>

                    {/* Total counter */}
                    <div className="flex items-center gap-3 px-4 py-3 rounded-xl border border-border bg-muted/30 shrink-0 dark:border-white/5 dark:bg-white/[0.03]">
                        <div className="w-8 h-8 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <Activity className="w-4 h-4" />
                        </div>
                        <div>
                            <p className="text-[10px] text-muted-foreground/40 uppercase tracking-widest font-bold leading-none">
                                Total events
                            </p>
                            <p className="text-lg font-black text-foreground">{pagination.total.toLocaleString()}</p>
                        </div>
                    </div>
                </div>

                {/* ── Filter bar ── */}
                <Card className="border-border bg-card mb-6 dark:border-white/5 dark:bg-white/[0.03]">
                    <CardContent className="p-5">
                        <div className="flex items-center gap-3 mb-4">
                            <Filter className="w-4 h-4 text-muted-foreground/50" />
                            <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground/50">
                                Filters
                            </span>
                            {activeFilterCount > 0 ? (
                                <Badge className="bg-primary/10 text-primary border-primary/20 text-[10px] font-bold px-2">
                                    {activeFilterCount} active
                                </Badge>
                            ) : null}
                            {activeFilterCount > 0 ? (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="ml-auto flex items-center gap-1 text-[11px] text-muted-foreground/50 hover:text-foreground transition-colors"
                                >
                                    <X className="w-3 h-3" />
                                    Clear all
                                </button>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-4">
                            {/* First row: main inputs */}
                            <div className="flex flex-col lg:flex-row gap-3">
                                {/* Search */}
                                <div className="relative flex-1 min-w-[240px]">
                                    <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground/40 pointer-events-none" />
                                    <Input
                                        id="q"
                                        value={form.data.q}
                                        onChange={(e) => form.setData('q', e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                submit();
                                            }
                                        }}
                                        placeholder="Search subject, model, user…"
                                        className="pl-10 rounded-xl border-border bg-muted/50 h-10 focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                    />
                                </div>

                                {/* Model */}
                                <div className="w-full lg:w-48 shrink-0">
                                    <AppSelect
                                        value={form.data.subject || ''}
                                        onValueChange={(v) => submit({ subject: v })}
                                        variant="dark"
                                        placeholder="All models"
                                        className="h-10"
                                    >
                                        <AppSelectItem value="">All models</AppSelectItem>
                                        {subject_types.map((t) => (
                                            <AppSelectItem key={t} value={t}>
                                                {t.split('\\').slice(-1)[0]}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                </div>

                                {/* Date Range */}
                                <div className="flex items-center gap-2 w-full lg:w-auto shrink-0">
                                    <div className="relative flex-1 lg:w-36">
                                        <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground/40 pointer-events-none" />
                                        <Input
                                            id="date_from"
                                            type="date"
                                            value={form.data.date_from}
                                            onChange={(e) => {
                                                form.setData('date_from', e.target.value);
                                                submit({ date_from: e.target.value });
                                            }}
                                            className="pl-9 rounded-xl border-border bg-muted/50 h-10 focus-visible:ring-primary/40 text-sm dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                    <span className="text-muted-foreground/30 text-xs shrink-0 select-none">to</span>
                                    <div className="relative flex-1 lg:w-36">
                                        <Calendar className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground/40 pointer-events-none" />
                                        <Input
                                            id="date_to"
                                            type="date"
                                            value={form.data.date_to}
                                            onChange={(e) => {
                                                form.setData('date_to', e.target.value);
                                                submit({ date_to: e.target.value });
                                            }}
                                            className="pl-9 rounded-xl border-border bg-muted/50 h-10 focus-visible:ring-primary/40 text-sm dark:border-white/10 dark:bg-white/5"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Second row: event pills and action buttons */}
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-3 border-t border-border dark:border-white/5">
                                {/* Event filter pills */}
                                <div className="flex flex-wrap gap-2 items-center">
                                    <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 mr-1 select-none">
                                        Event:
                                    </span>
                                    {(['all', ...EVENTS] as const).map((e) => {
                                        const isActive = (form.data.event || 'all') === e;

                                        return (
                                            <button
                                                key={e}
                                                type="button"
                                                onClick={() =>
                                                    submit({ event: e === 'all' ? '' : e })
                                                }
                                                className={cn(
                                                    'h-7 px-3 rounded-full text-[11px] font-bold uppercase tracking-wider border transition-all',
                                                    isActive
                                                        ? e === 'all'
                                                            ? 'bg-primary text-primary-foreground border-primary shadow-sm shadow-primary/30'
                                                            : e === 'created'
                                                              ? 'bg-emerald-500/20 text-emerald-600 border-emerald-500/30 dark:text-emerald-400'
                                                              : e === 'deleted'
                                                                ? 'bg-red-500/20 text-red-600 border-red-500/30 dark:text-red-400'
                                                                : 'bg-sky-500/20 text-sky-600 border-sky-500/30 dark:text-sky-400'
                                                        : 'bg-muted/30 text-muted-foreground/60 border-border hover:border-border hover:text-foreground dark:bg-white/[0.03] dark:border-white/5 dark:hover:border-white/10',
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
                                            className="rounded-xl h-9 text-xs text-muted-foreground/50 hover:text-foreground"
                                            onClick={resetFilters}
                                        >
                                            <X className="w-3 h-3 mr-1" />
                                            Clear filters
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        className="rounded-xl h-9 px-5 text-xs font-semibold"
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
                <Card className="border-border bg-card overflow-hidden dark:border-white/5 dark:bg-white/[0.03]">
                    {/* Table header */}
                    <div className="px-6 py-4 border-b border-border bg-muted/20 flex items-center justify-between dark:border-white/5 dark:bg-white/[0.02]">
                        <h2 className="text-sm font-bold text-foreground/80">Events</h2>
                        <span className="text-[11px] text-muted-foreground/50 font-mono">
                            {pagination.total.toLocaleString()} total
                        </span>
                    </div>

                    <CardContent className="p-0">
                        {logs.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-20 text-center">
                                <div className="w-14 h-14 rounded-2xl bg-muted/30 border border-dashed border-border flex items-center justify-center mb-4 dark:bg-white/[0.03] dark:border-white/10">
                                    <Activity className="w-6 h-6 text-muted-foreground/20" />
                                </div>
                                <p className="text-sm font-medium text-foreground/50">
                                    No activity found
                                </p>
                                <p className="text-xs text-muted-foreground/40 mt-1">
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
                                            onOpenChange={(v) => setOpenId(v ? log.id : null)}
                                        >
                                            <CollapsibleTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="w-full text-left px-6 py-4 hover:bg-muted/30 transition-colors group dark:hover:bg-white/[0.02]"
                                                >
                                                    <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-center w-full">
                                                        {/* Col 1: Event & Model */}
                                                        <div className="md:col-span-4 flex items-center gap-3 min-w-0">
                                                            <div
                                                                className={cn(
                                                                    'w-2 h-2 rounded-full shrink-0',
                                                                    style.dot,
                                                                )}
                                                            />
                                                            <Badge
                                                                className={cn(
                                                                    'text-[9px] uppercase font-bold tracking-wider border px-2 py-0.5 shrink-0',
                                                                    style.badge,
                                                                )}
                                                            >
                                                                {log.event}
                                                            </Badge>
                                                            <div className="truncate min-w-0">
                                                                <span className="text-sm font-bold text-foreground/90 truncate block md:inline">
                                                                    {modelName}
                                                                </span>
                                                                <span className="text-xs text-muted-foreground/40 font-mono ml-1.5 shrink-0">
                                                                    #{log.subject_id ?? '—'}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        {/* Col 2: Changed fields preview / Subject label */}
                                                        <div className="md:col-span-3 min-w-0 flex flex-col gap-1">
                                                            {log.subject_label ? (
                                                                <span className="text-xs font-semibold text-foreground/75 truncate">
                                                                    {log.subject_label}
                                                                </span>
                                                            ) : null}
                                                            {previewKeys.length > 0 ? (
                                                                <div className="flex flex-wrap gap-1 items-center">
                                                                    {previewKeys.map((k) => (
                                                                        <span
                                                                            key={k}
                                                                            className="inline-flex items-center rounded-md border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] text-muted-foreground/50 whitespace-nowrap dark:border-white/5 dark:bg-white/[0.02]"
                                                                        >
                                                                            {titleCaseKey(k)}
                                                                        </span>
                                                                    ))}
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
                                                        <div className="md:col-span-3 flex items-center gap-2.5 min-w-0 md:justify-end">
                                                            {log.causer ? (
                                                                <>
                                                                    <div className="text-right hidden sm:block min-w-0">
                                                                        <p className="text-xs font-semibold text-foreground/80 leading-none truncate">
                                                                            {log.causer.name}
                                                                        </p>
                                                                        <p className="text-[9px] text-muted-foreground/40 mt-1 truncate">
                                                                            {log.causer.email}
                                                                        </p>
                                                                    </div>
                                                                    <CauserAvatar
                                                                        name={log.causer.name}
                                                                    />
                                                                </>
                                                            ) : (
                                                                <span className="text-xs text-muted-foreground/40">
                                                                    System
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Col 4: Time & Action */}
                                                        <div className="md:col-span-2 flex items-center justify-end gap-3 shrink-0">
                                                            <span className="text-[10px] text-muted-foreground/40 font-mono whitespace-nowrap">
                                                                {formatDisplayDate(
                                                                    log.created_at,
                                                                )}
                                                            </span>
                                                            {isOpen ? (
                                                                <ChevronUp className="w-3.5 h-3.5 text-muted-foreground/40 shrink-0" />
                                                            ) : (
                                                                <ChevronDown className="w-3.5 h-3.5 text-muted-foreground/30 group-hover:text-muted-foreground/60 transition-colors shrink-0" />
                                                            )}
                                                        </div>
                                                    </div>
                                                </button>
                                            </CollapsibleTrigger>

                                            {/* Expanded detail */}
                                            <CollapsibleContent>
                                                <div className="px-6 pb-5 border-l-2 border-border ml-[2.125rem] dark:border-white/5">
                                                    {changedKeys.length > 0 ? (
                                                        <div className="rounded-xl border border-border bg-muted/20 overflow-hidden mt-2 dark:border-white/5 dark:bg-white/[0.02]">
                                                            <div className="overflow-x-auto">
                                                                <table className="w-full border-collapse text-left text-xs">
                                                                    <thead>
                                                                        <tr className="border-b border-border bg-muted/20 text-[10px] uppercase tracking-wider text-muted-foreground/60 font-bold select-none dark:border-white/5 dark:bg-white/[0.02]">
                                                                            <th className="py-2.5 px-4 w-1/3">Field</th>
                                                                            <th className="py-2.5 px-4 w-1/3">Old Value</th>
                                                                            <th className="py-2.5 px-4 w-1/3">New Value</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody className="divide-y divide-border dark:divide-white/5">
                                                                        {changedKeys.map((k) => (
                                                                            <tr
                                                                                key={k}
                                                                                className="hover:bg-muted/20 transition-colors dark:hover:bg-white/[0.01]"
                                                                            >
                                                                                <td className="py-3 px-4 font-semibold text-muted-foreground/75 whitespace-nowrap">
                                                                                    {titleCaseKey(k)}
                                                                                </td>
                                                                                <td className="py-3 px-4 text-muted-foreground/50 line-through break-all font-mono text-[11px]">
                                                                                    {formatDisplayValue(
                                                                                        log.old_values?.[
                                                                                            k
                                                                                        ],
                                                                                    )}
                                                                                </td>
                                                                                <td className="py-3 px-4 text-foreground/80 font-semibold break-all font-mono text-[11px]">
                                                                                    {formatDisplayValue(
                                                                                        log.new_values?.[
                                                                                            k
                                                                                        ],
                                                                                    )}
                                                                                </td>
                                                                            </tr>
                                                                        ))}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <p className="text-xs text-muted-foreground/40 py-3 mt-1">
                                                            No field-level diff available for this
                                                            event.
                                                        </p>
                                                    )}

                                                    {/* Meta row */}
                                                    {(log.ip ?? log.description) ? (
                                                        <div className="flex flex-wrap gap-4 mt-3 pl-4">
                                                            {log.ip ? (
                                                                <span className="text-[10px] text-muted-foreground/40 font-mono">
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
                                                                    {log.description}
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
