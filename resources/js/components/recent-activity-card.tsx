import { Activity } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export type RecentActivityItem = {
    id: number;
    event: string | null;
    description: string;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
};

const HIDDEN_ACTIVITY_KEYS = new Set([
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

function eventColor(event: string | null): string {
    switch (event?.toLowerCase()) {
        case 'created':
            return 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400';
        case 'updated':
            return 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400';
        case 'deleted':
            return 'bg-red-500/10 text-red-600 border-red-500/20 dark:text-red-400';
        default:
            return 'bg-muted/50 text-muted-foreground border-border dark:bg-white/5 dark:border-white/10';
    }
}

function changedKeys(oldValues: Record<string, unknown> | null, newValues: Record<string, unknown> | null): string[] {
    const keys = new Set<string>([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]);

    return [...keys].filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k)).sort((a, b) => a.localeCompare(b));
}

export function RecentActivityCard({
    items,
    description,
}: {
    items: RecentActivityItem[];
    description: string;
}) {
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    return (
        <Card className="glass-card mt-8 border-border bg-card dark:border-white/5 dark:bg-white/5">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border pb-4 dark:border-white/5">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                        <Activity className="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">Recent activity</CardTitle>
                        <div className="text-[10px] text-muted-foreground/50">{description}</div>
                    </div>
                </div>
                <Badge className="border-border bg-muted/50 font-mono text-xs text-muted-foreground dark:border-white/10 dark:bg-white/5">
                    {items.length}
                </Badge>
            </CardHeader>
            <CardContent className="p-0">
                {items.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                            <Activity className="h-5 w-5 text-muted-foreground/20" />
                        </div>
                        <p className="text-sm text-muted-foreground/50">No activity recorded yet.</p>
                    </div>
                ) : (
                    <div className="divide-y divide-border dark:divide-white/5">
                        {items.map((item) => {
                            const keys = changedKeys(item.old_values, item.new_values);
                            const isExpanded = expandedActivity[item.id] ?? false;
                            const shown = isExpanded ? keys : keys.slice(0, 4);
                            const showDescription =
                                item.description.trim().toLowerCase() !== (item.event ?? '').trim().toLowerCase();

                            return (
                                <div
                                    key={item.id}
                                    className="px-6 py-4 transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.015]"
                                >
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0 flex-1 space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    className={cn(
                                                        'border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider',
                                                        eventColor(item.event),
                                                    )}
                                                >
                                                    {item.event ?? 'event'}
                                                </Badge>
                                                <span className="text-sm font-semibold text-foreground/90">
                                                    {item.causer?.name ?? 'System'}
                                                </span>
                                                {item.causer?.email ? (
                                                    <span className="text-xs text-muted-foreground/50">({item.causer.email})</span>
                                                ) : null}
                                            </div>

                                            {showDescription ? (
                                                <p className="text-xs text-muted-foreground/70">{item.description}</p>
                                            ) : null}

                                            {shown.length > 0 ? (
                                                <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                    {shown.map((key) => (
                                                        <span
                                                            key={key}
                                                            className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5"
                                                        >
                                                            {titleCaseKey(key)}:{' '}
                                                            <span className="text-muted-foreground/70">
                                                                {formatDisplayValue(item.old_values?.[key])}
                                                            </span>{' '}
                                                            →{' '}
                                                            <span className="text-foreground/90">
                                                                {formatDisplayValue(item.new_values?.[key])}
                                                            </span>
                                                        </span>
                                                    ))}
                                                    {keys.length > 4 ? (
                                                        <button
                                                            type="button"
                                                            className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground transition hover:bg-accent dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                                            onClick={() =>
                                                                setExpandedActivity((prev) => ({
                                                                    ...prev,
                                                                    [item.id]: !(prev[item.id] ?? false),
                                                                }))
                                                            }
                                                        >
                                                            {isExpanded ? 'Show less' : `+${keys.length - 4} more`}
                                                        </button>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                        </div>

                                        <div className="shrink-0 text-xs text-muted-foreground/50">
                                            {formatDisplayDate(item.created_at)}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
