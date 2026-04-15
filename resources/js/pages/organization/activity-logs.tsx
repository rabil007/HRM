import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDown, ArrowRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

function formatDate(value: string): string {
    const dt = new Date(value);

    if (Number.isNaN(dt.getTime())) {
        return value;
    }

    return dt.toLocaleString();
}

function eventBadgeVariant(
    event: string
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (event === 'deleted') {
        return 'destructive';
    }

    if (event === 'created') {
        return 'default';
    }

    return 'secondary';
}

function pickChangedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys].sort((a, b) => a.localeCompare(b));
}

const HIDDEN_KEYS = new Set([
    'id',
    'company_id',
    'created_at',
    'updated_at',
    'deleted_at',
    'remember_token',
    'password',
]);

function titleCaseKey(key: string): string {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (m) => m.toUpperCase());
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'string') {
        return value;
    }

    try {
        return JSON.stringify(value);
    } catch {
        return String(value);
    }
}

function buildSummary(log: AuditLog): string {
    const who = log.causer?.name ?? 'System';
    const subject = log.subject_label ?? log.description ?? log.subject_name;

    return `${who} ${log.event} ${log.subject_name}${subject ? `: ${subject}` : ''}`;
}

export default function ActivityLogs({
    logs,
    filters,
    subject_types,
}: {
    logs: Pagination<AuditLog>;
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

    const events = useMemo(() => ['created', 'updated', 'deleted'], []);
    const [openId, setOpenId] = useState<number | null>(null);

    const submit = (next?: Partial<typeof form.data>) => {
        const data = { ...form.data, ...(next ?? {}) };
        form.setData(data);
        router.get('/organization/activity-logs', data, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Activity logs" />

            <div className="mx-auto w-full max-w-6xl space-y-6">
                <div className="flex flex-col gap-1">
                    <div className="text-2xl font-semibold tracking-tight">
                        Activity logs
                    </div>
                    <div className="text-sm text-muted-foreground">
                        Track changes across your organization data.
                    </div>
                </div>

                <Card>
                    <CardHeader className="space-y-4">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <CardTitle className="text-base">Filters</CardTitle>

                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => {
                                        const data = {
                                            q: '',
                                            event: '',
                                            subject: '',
                                            date_from: filters.date_from ?? '',
                                            date_to: filters.date_to ?? '',
                                        };

                                        form.setData(data);
                                        router.get('/organization/activity-logs', data, {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        });
                                    }}
                                >
                                    Reset
                                </Button>
                                <Button type="button" onClick={() => submit()}>
                                    Apply
                                </Button>
                            </div>
                        </div>

                        <div className="grid gap-3 lg:grid-cols-12">
                            <div className="lg:col-span-5">
                                <Label htmlFor="q">Search</Label>
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
                                    placeholder="Subject, model, user..."
                                    className="mt-1"
                                />
                            </div>

                            <div className="lg:col-span-4">
                                <Label>Model</Label>
                                <select
                                    className="mt-1 h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm ring-offset-background focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    value={form.data.subject || 'all'}
                                    onChange={(e) =>
                                        submit({
                                            subject:
                                                e.target.value === 'all'
                                                    ? ''
                                                    : e.target.value,
                                        })
                                    }
                                >
                                    <option value="all">All</option>
                                    {subject_types.map((t) => (
                                        <option key={t} value={t}>
                                            {t.split('\\').slice(-1)[0]}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="lg:col-span-6">
                                <Label htmlFor="date_from">From</Label>
                                <Input
                                    id="date_from"
                                    type="date"
                                    value={form.data.date_from}
                                    onChange={(e) =>
                                        form.setData('date_from', e.target.value)
                                    }
                                    className="mt-1"
                                />
                            </div>

                            <div className="lg:col-span-6">
                                <Label htmlFor="date_to">To</Label>
                                <Input
                                    id="date_to"
                                    type="date"
                                    value={form.data.date_to}
                                    onChange={(e) =>
                                        form.setData('date_to', e.target.value)
                                    }
                                    className="mt-1"
                                />
                            </div>

                            <div className="lg:col-span-12">
                                <Label>Event</Label>
                                <div className="mt-1 flex flex-wrap gap-2">
                                    {['all', ...events].map((e) => {
                                        const isActive =
                                            (form.data.event || 'all') === e;

                                        return (
                                            <Button
                                                key={e}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    isActive
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className="h-8 rounded-full px-3 capitalize"
                                                onClick={() =>
                                                    submit({
                                                        event:
                                                            e === 'all'
                                                                ? ''
                                                                : e,
                                                    })
                                                }
                                            >
                                                {e}
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">Recent activity</CardTitle>
                        <div className="text-sm text-muted-foreground">
                            {logs.data.length} item(s)
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {logs.data.length === 0 ? (
                            <div className="py-12 text-center text-sm text-muted-foreground">
                                No activity found.
                            </div>
                        ) : (
                            <div className="divide-y">
                                {logs.data.map((log) => {
                                    const changedKeys = pickChangedKeys(log.old_values, log.new_values).filter(
                                        (k) => !HIDDEN_KEYS.has(k)
                                    );
                                    const previewKeys = changedKeys.slice(0, 3);
                                    const isOpen = openId === log.id;
                                    const title = buildSummary(log);

                                    return (
                                        <Collapsible
                                            key={log.id}
                                            open={isOpen}
                                            onOpenChange={(v) =>
                                                setOpenId(v ? log.id : null)
                                            }
                                        >
                                            <div className="px-4 py-4 sm:px-6">
                                                <CollapsibleTrigger asChild>
                                                    <button
                                                        type="button"
                                                        className="flex w-full items-start justify-between gap-4 text-left"
                                                    >
                                                        <div className="min-w-0 space-y-1">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <Badge
                                                                    variant={eventBadgeVariant(
                                                                        log.event
                                                                    )}
                                                                >
                                                                    {log.event}
                                                                </Badge>
                                                                <span className="truncate font-medium">
                                                                    {log.subject_name}
                                                                    <span className="text-muted-foreground">
                                                                        {' '}
                                                                        #{log.subject_id ?? '-'}
                                                                    </span>
                                                                </span>
                                                            </div>
                                                            <div className="truncate text-sm text-muted-foreground">
                                                                {title}
                                                            </div>
                                                            {previewKeys.length >
                                                                0 && (
                                                                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground/80">
                                                                    <span className="rounded-md bg-muted px-2 py-1">
                                                                        {previewKeys.join(
                                                                            ', '
                                                                        )}
                                                                        {changedKeys.length >
                                                                        previewKeys.length
                                                                            ? ` +${changedKeys.length - previewKeys.length} more`
                                                                            : ''}
                                                                    </span>
                                                                </div>
                                                            )}
                                                        </div>

                                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                                            <div className="text-sm text-muted-foreground">
                                                                {log.causer ? (
                                                                    <span>
                                                                        {
                                                                            log
                                                                                .causer
                                                                                .name
                                                                        }{' '}
                                                                        <span className="text-muted-foreground/70">
                                                                            (
                                                                            {
                                                                                log
                                                                                    .causer
                                                                                    .email
                                                                            }
                                                                            )
                                                                        </span>
                                                                    </span>
                                                                ) : (
                                                                    'System'
                                                                )}
                                                            </div>
                                                            <div className="flex items-center gap-2 text-xs text-muted-foreground/80">
                                                                <span>
                                                                    {formatDate(
                                                                        log.created_at
                                                                    )}
                                                                </span>
                                                                <ChevronDown
                                                                    className={`h-4 w-4 transition-transform ${
                                                                        isOpen
                                                                            ? 'rotate-180'
                                                                            : ''
                                                                    }`}
                                                                />
                                                            </div>
                                                        </div>
                                                    </button>
                                                </CollapsibleTrigger>

                                                <CollapsibleContent className="mt-4">
                                                    <div className="grid gap-4 rounded-lg border bg-card p-4 md:grid-cols-2">
                                                        {changedKeys.length >
                                                            0 && (
                                                            <div className="md:col-span-2 space-y-2">
                                                                <div className="mb-2 text-sm font-medium">
                                                                    Changed fields
                                                                </div>
                                                                <div className="grid gap-2 md:grid-cols-2">
                                                                    {changedKeys.map(
                                                                        (k) => (
                                                                            <div
                                                                                key={k}
                                                                                className="flex items-center justify-between gap-3 rounded-md border bg-background px-3 py-2 text-xs"
                                                                            >
                                                                                <span className="font-medium">
                                                                                    {titleCaseKey(k)}
                                                                                </span>
                                                                                <span className="flex items-center gap-2 text-muted-foreground">
                                                                                    <span className="max-w-[160px] truncate">
                                                                                        {formatValue(
                                                                                            log.old_values?.[k]
                                                                                        )}
                                                                                    </span>
                                                                                    <ArrowRight className="h-3.5 w-3.5" />
                                                                                    <span className="max-w-[160px] truncate text-foreground">
                                                                                        {formatValue(
                                                                                            log.new_values?.[k]
                                                                                        )}
                                                                                    </span>
                                                                                </span>
                                                                            </div>
                                                                        )
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                </CollapsibleContent>
                                            </div>
                                        </Collapsible>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

