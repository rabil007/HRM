import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    Calendar,
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Filter,
    Search,
    ShieldAlert,
    Users,
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
import {
    formatActivityFieldLabel,
    formatDisplayDate,
    formatDisplayValue,
} from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';

type Importance = 'normal' | 'important' | 'critical';

type AuditLog = {
    id: number;
    event: string | null;
    subject_type: string | null;
    subject_name: string;
    subject_id: number | string | null;
    subject_label: string | null;
    subject_type_label: string;
    description: string | null;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    module_key: string;
    module_label: string;
    importance: Importance;
    headline: string;
    record_url: string | null;
    created_at: string;
};

type FilterState = {
    q: string;
    event: string;
    module: string;
    user_id: string;
    importance: string;
    date_from: string;
    date_to: string;
};

type ModuleOption = { key: string; label: string };
type UserOption = { id: number; name: string; email: string };
type Summary = {
    total: number;
    users: number;
    important: number;
    critical: number;
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

const EVENTS = ['created', 'updated', 'deleted'] as const;
const IMPORTANCE_OPTIONS = ['normal', 'important', 'critical'] as const;

function pickChangedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((key) => !HIDDEN_KEYS.has(key))
        .sort((a, b) => a.localeCompare(b));
}

function normalizeEvent(event: string | null | undefined): string {
    return (event ?? '').trim().toLowerCase();
}

function eventStyle(event: string | null | undefined): {
    badge: string;
    dot: string;
} {
    switch (normalizeEvent(event)) {
        case 'created':
            return {
                badge: 'border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                dot: 'bg-emerald-400',
            };
        case 'deleted':
            return {
                badge: 'border-red-500/20 bg-red-500/10 text-red-600 dark:text-red-400',
                dot: 'bg-red-400',
            };
        default:
            return {
                badge: 'border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-400',
                dot: 'bg-sky-400',
            };
    }
}

function importanceStyle(importance: Importance): string {
    if (importance === 'critical') {
        return 'border-red-500/25 bg-red-500/10 text-red-600 dark:text-red-400';
    }

    if (importance === 'important') {
        return 'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-400';
    }

    return 'border-border bg-muted/40 text-muted-foreground';
}

function formatDateInput(date: Date): string {
    const year = date.getFullYear();
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function relativeDateRange(daysAgo: number): Pick<FilterState, 'date_from' | 'date_to'> {
    const today = new Date();
    const from = new Date(today);
    from.setDate(today.getDate() - daysAgo);

    return {
        date_from: formatDateInput(from),
        date_to: formatDateInput(today),
    };
}

function CauserAvatar({ name }: { name: string | null }) {
    const source = name?.trim() || '?';
    const initials = source
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();

    return (
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/20 bg-primary/10">
            <span className="text-[10px] font-black text-primary">{initials}</span>
        </div>
    );
}

export default function ActivityLogs({
    logs,
    pagination,
    filters,
    modules,
    users,
    summary,
}: {
    logs: AuditLog[];
    pagination: PaginationMeta;
    filters: FilterState;
    modules: ModuleOption[];
    users: UserOption[];
    summary: Summary;
}) {
    const form = useForm<FilterState>({
        q: filters.q ?? '',
        event: filters.event ?? '',
        module: filters.module ?? '',
        user_id: filters.user_id ?? '',
        importance: filters.importance ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const [openId, setOpenId] = useState<number | null>(null);

    const list = useServerPaginationFilters({
        url: '/organization/activity-logs',
        search: filters.q ?? '',
        filters: {
            event: filters.event,
            module: filters.module,
            user_id: filters.user_id,
            importance: filters.importance,
            date_from: filters.date_from,
            date_to: filters.date_to,
        },
        searchKey: 'q',
        pagination,
    });

    const submit = (next?: Partial<FilterState>) => {
        const data = { ...form.data, ...(next ?? {}) };
        form.setData(data);
        list.visit({ ...data, page: null });
    };

    const resetFilters = () => {
        const today = relativeDateRange(0);
        const data: FilterState = {
            q: '',
            event: '',
            module: '',
            user_id: '',
            importance: '',
            ...today,
        };

        form.setData(data);
        list.visit({ ...data, page: null });
    };

    const activeFilterCount = useMemo(() => {
        return [
            form.data.q,
            form.data.event,
            form.data.module,
            form.data.user_id,
            form.data.importance,
        ].filter(Boolean).length;
    }, [form.data]);

    const applyDatePreset = (daysAgo: number) => {
        submit(relativeDateRange(daysAgo));
    };

    const summaryCards = [
        {
            label: 'Events in range',
            value: summary.total,
            icon: Activity,
        },
        {
            label: 'Active users',
            value: summary.users,
            icon: Users,
        },
        {
            label: 'Important',
            value: summary.important,
            icon: ShieldAlert,
        },
        {
            label: 'Critical',
            value: summary.critical,
            icon: ShieldAlert,
        },
    ];

    return (
        <>
            <Head title="Activity logs" />

            <Main>
                <div className="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <div className="mb-1 flex items-center gap-2">
                            <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                Organization
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight text-foreground">
                            Activity intelligence
                        </h1>
                        <p className="mt-1 text-sm font-medium text-muted-foreground/70">
                            Understand who changed what, where it happened, and what needs attention.
                        </p>
                    </div>
                </div>

                <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {summaryCards.map((card) => {
                        const Icon = card.icon;

                        return (
                            <Card key={card.label} className="border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                                <CardContent className="flex items-center justify-between p-4">
                                    <div>
                                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                                            {card.label}
                                        </p>
                                        <p className="mt-1 text-2xl font-black text-foreground">
                                            {card.value.toLocaleString()}
                                        </p>
                                    </div>
                                    <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary">
                                        <Icon className="h-4 w-4" />
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <Card className="mb-6 border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                    <CardContent className="p-5">
                        <div className="mb-4 flex items-center gap-3">
                            <Filter className="h-4 w-4 text-muted-foreground/50" />
                            <span className="text-xs font-bold tracking-widest text-muted-foreground/50 uppercase">
                                Investigate
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
                                    Clear filters
                                </button>
                            ) : null}
                        </div>

                        <div className="grid gap-3 xl:grid-cols-12">
                            <div className="relative xl:col-span-4">
                                <Search className="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground/40" />
                                <Input
                                    id="q"
                                    value={form.data.q}
                                    onChange={(event) => form.setData('q', event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            submit();
                                        }
                                    }}
                                    placeholder="Search user, employee, vessel, document, value…"
                                    className="h-10 rounded-xl border-border bg-muted/50 pl-10 dark:border-white/10 dark:bg-white/5"
                                />
                            </div>

                            <div className="xl:col-span-2">
                                <AppSelect
                                    value={form.data.module}
                                    onValueChange={(value) => submit({ module: value })}
                                    variant="dark"
                                    placeholder="All modules"
                                    className="h-10"
                                >
                                    <AppSelectItem value="">All modules</AppSelectItem>
                                    {modules.map((module) => (
                                        <AppSelectItem key={module.key} value={module.key}>
                                            {module.label}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>

                            <div className="xl:col-span-2">
                                <AppSelect
                                    value={form.data.user_id}
                                    onValueChange={(value) => submit({ user_id: value })}
                                    variant="dark"
                                    placeholder="All users"
                                    className="h-10"
                                >
                                    <AppSelectItem value="">All users</AppSelectItem>
                                    {users.map((user) => (
                                        <AppSelectItem key={user.id} value={String(user.id)}>
                                            {user.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>

                            <div className="xl:col-span-2">
                                <AppSelect
                                    value={form.data.importance}
                                    onValueChange={(value) => submit({ importance: value })}
                                    variant="dark"
                                    placeholder="All importance"
                                    className="h-10"
                                >
                                    <AppSelectItem value="">All importance</AppSelectItem>
                                    {IMPORTANCE_OPTIONS.map((importance) => (
                                        <AppSelectItem key={importance} value={importance}>
                                            {importance.charAt(0).toUpperCase() + importance.slice(1)}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>

                            <Button
                                type="button"
                                className="h-10 rounded-xl xl:col-span-2"
                                onClick={() => submit()}
                            >
                                Apply filters
                            </Button>
                        </div>

                        <div className="mt-4 flex flex-col justify-between gap-3 border-t border-border pt-4 lg:flex-row lg:items-center dark:border-white/5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                                    Event
                                </span>
                                {(['all', ...EVENTS] as const).map((event) => {
                                    const active = (form.data.event || 'all') === event;

                                    return (
                                        <button
                                            key={event}
                                            type="button"
                                            onClick={() => submit({ event: event === 'all' ? '' : event })}
                                            className={cn(
                                                'h-7 rounded-full border px-3 text-[11px] font-bold tracking-wider uppercase transition-all',
                                                active
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border bg-muted/30 text-muted-foreground/60 hover:text-foreground dark:border-white/5 dark:bg-white/[0.03]',
                                            )}
                                        >
                                            {event}
                                        </button>
                                    );
                                })}
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                                    Date
                                </span>
                                <Button type="button" variant="outline" className="h-8 rounded-lg px-3 text-xs" onClick={() => applyDatePreset(0)}>
                                    Today
                                </Button>
                                <Button type="button" variant="outline" className="h-8 rounded-lg px-3 text-xs" onClick={() => applyDatePreset(1)}>
                                    Yesterday + today
                                </Button>
                                <Button type="button" variant="outline" className="h-8 rounded-lg px-3 text-xs" onClick={() => applyDatePreset(6)}>
                                    7 days
                                </Button>
                                <Button type="button" variant="outline" className="h-8 rounded-lg px-3 text-xs" onClick={() => applyDatePreset(29)}>
                                    30 days
                                </Button>
                                <div className="relative">
                                    <Calendar className="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground/40" />
                                    <Input
                                        type="date"
                                        value={form.data.date_from}
                                        onChange={(event) => submit({ date_from: event.target.value })}
                                        className="h-8 w-36 rounded-lg pl-8 text-xs"
                                    />
                                </div>
                                <span className="text-xs text-muted-foreground/40">to</span>
                                <Input
                                    type="date"
                                    value={form.data.date_to}
                                    onChange={(event) => submit({ date_to: event.target.value })}
                                    className="h-8 w-36 rounded-lg text-xs"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="overflow-hidden border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                    <div className="flex items-center justify-between border-b border-border bg-muted/20 px-6 py-4 dark:border-white/5 dark:bg-white/[0.02]">
                        <div>
                            <h2 className="text-sm font-bold text-foreground/80">Organization activity</h2>
                            <p className="mt-0.5 text-[11px] text-muted-foreground/50">
                                Human actions only. Expand an item to inspect the exact changes.
                            </p>
                        </div>
                        <span className="font-mono text-[11px] text-muted-foreground/50">
                            {pagination.total.toLocaleString()} results
                        </span>
                    </div>

                    <CardContent className="p-0">
                        {logs.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-20 text-center">
                                <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                                    <Activity className="h-6 w-6 text-muted-foreground/20" />
                                </div>
                                <p className="text-sm font-medium text-foreground/50">No activity found</p>
                                <p className="mt-1 text-xs text-muted-foreground/40">Try another date range or filter.</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-border dark:divide-white/5">
                                {logs.map((log) => {
                                    const changedKeys = pickChangedKeys(log.old_values, log.new_values);
                                    const previewKeys = changedKeys.slice(0, 3);
                                    const isOpen = openId === log.id;
                                    const style = eventStyle(log.event);

                                    return (
                                        <Collapsible
                                            key={log.id}
                                            open={isOpen}
                                            onOpenChange={(open) => setOpenId(open ? log.id : null)}
                                        >
                                            <CollapsibleTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="group w-full px-6 py-4 text-left transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.02]"
                                                >
                                                    <div className="grid items-center gap-4 lg:grid-cols-12">
                                                        <div className="flex min-w-0 items-start gap-3 lg:col-span-6">
                                                            <div className={cn('mt-2 h-2 w-2 shrink-0 rounded-full', style.dot)} />
                                                            <div className="min-w-0">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <Badge className={cn('border px-2 py-0.5 text-[9px] font-bold tracking-wider uppercase', style.badge)}>
                                                                        {normalizeEvent(log.event) || 'activity'}
                                                                    </Badge>
                                                                    <Badge className={cn('border px-2 py-0.5 text-[9px] font-bold uppercase', importanceStyle(log.importance))}>
                                                                        {log.importance}
                                                                    </Badge>
                                                                    <span className="text-[10px] font-semibold text-muted-foreground/50">
                                                                        {log.module_label}
                                                                    </span>
                                                                </div>
                                                                <p className="mt-1.5 truncate text-sm font-bold text-foreground/90">
                                                                    {log.headline}
                                                                </p>
                                                                {previewKeys.length > 0 ? (
                                                                    <div className="mt-1.5 flex flex-wrap gap-1">
                                                                        {previewKeys.map((key) => (
                                                                            <span
                                                                                key={key}
                                                                                className="rounded-md border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] text-muted-foreground/55 dark:border-white/5"
                                                                            >
                                                                                {formatActivityFieldLabel(key)}
                                                                            </span>
                                                                        ))}
                                                                        {changedKeys.length > previewKeys.length ? (
                                                                            <span className="rounded-md border border-border bg-muted/30 px-1.5 py-0.5 text-[9px] text-muted-foreground/40 dark:border-white/5">
                                                                                +{changedKeys.length - previewKeys.length}
                                                                            </span>
                                                                        ) : null}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        </div>

                                                        <div className="min-w-0 lg:col-span-2">
                                                            <p className="truncate text-xs font-semibold text-foreground/70">
                                                                {log.subject_label || log.subject_type_label}
                                                            </p>
                                                            <p className="mt-0.5 truncate text-[10px] text-muted-foreground/40">
                                                                {log.subject_type_label}
                                                                {log.subject_id ? ` · #${log.subject_id}` : ''}
                                                            </p>
                                                        </div>

                                                        <div className="flex min-w-0 items-center gap-2.5 lg:col-span-2 lg:justify-end">
                                                            <div className="hidden min-w-0 text-right sm:block">
                                                                <p className="truncate text-xs font-semibold text-foreground/80">
                                                                    {log.causer?.name ?? 'User'}
                                                                </p>
                                                                <p className="mt-1 truncate text-[9px] text-muted-foreground/40">
                                                                    {log.causer?.email ?? ''}
                                                                </p>
                                                            </div>
                                                            <CauserAvatar name={log.causer?.name ?? null} />
                                                        </div>

                                                        <div className="flex items-center justify-end gap-3 lg:col-span-2">
                                                            <span className="font-mono text-[10px] whitespace-nowrap text-muted-foreground/40">
                                                                {formatDisplayDate(log.created_at)}
                                                            </span>
                                                            {isOpen ? (
                                                                <ChevronUp className="h-3.5 w-3.5 text-muted-foreground/40" />
                                                            ) : (
                                                                <ChevronDown className="h-3.5 w-3.5 text-muted-foreground/30 group-hover:text-muted-foreground/60" />
                                                            )}
                                                        </div>
                                                    </div>
                                                </button>
                                            </CollapsibleTrigger>

                                            <CollapsibleContent>
                                                <div className="ml-8 border-l-2 border-border px-6 pb-5 dark:border-white/5">
                                                    {changedKeys.length > 0 ? (
                                                        <div className="mt-2 overflow-hidden rounded-xl border border-border bg-muted/20 dark:border-white/5 dark:bg-white/[0.02]">
                                                            <div className="overflow-x-auto">
                                                                <table className="w-full border-collapse text-left text-xs">
                                                                    <thead>
                                                                        <tr className="border-b border-border bg-muted/20 text-[10px] font-bold tracking-wider text-muted-foreground/60 uppercase dark:border-white/5">
                                                                            <th className="w-1/3 px-4 py-2.5">Field</th>
                                                                            <th className="w-1/3 px-4 py-2.5">Old value</th>
                                                                            <th className="w-1/3 px-4 py-2.5">New value</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody className="divide-y divide-border dark:divide-white/5">
                                                                        {changedKeys.map((key) => (
                                                                            <tr key={key}>
                                                                                <td className="px-4 py-3 font-semibold text-muted-foreground/75">
                                                                                    {formatActivityFieldLabel(key)}
                                                                                </td>
                                                                                <td className="px-4 py-3 font-mono text-[11px] break-all text-muted-foreground/50 line-through">
                                                                                    {formatDisplayValue(log.old_values?.[key])}
                                                                                </td>
                                                                                <td className="px-4 py-3 font-mono text-[11px] font-semibold break-all text-foreground/80">
                                                                                    {formatDisplayValue(log.new_values?.[key])}
                                                                                </td>
                                                                            </tr>
                                                                        ))}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <p className="mt-2 py-3 text-xs text-muted-foreground/40">
                                                            No field-level diff is available for this action.
                                                        </p>
                                                    )}

                                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 pl-4">
                                                        <div className="text-[10px] text-muted-foreground/45">
                                                            {log.description && log.description.toLowerCase() !== normalizeEvent(log.event)
                                                                ? log.description
                                                                : `${log.module_label} · ${log.subject_type_label}`}
                                                        </div>
                                                        {log.record_url ? (
                                                            <Link
                                                                href={log.record_url}
                                                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                                                                onClick={(event) => event.stopPropagation()}
                                                            >
                                                                Open affected record
                                                                <ExternalLink className="h-3.5 w-3.5" />
                                                            </Link>
                                                        ) : null}
                                                    </div>
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
