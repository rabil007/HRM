import { Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { Activity, BadgeDollarSign, Crown, Users } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PositionFormSheet } from '@/features/organization/positions/components/position-form-sheet';
import type {
    Company,
    DepartmentOption,
    Position,
    PositionFormData,
} from '@/features/organization/positions/types';
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';

type ActivityItem = {
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
    return key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}

function eventColor(event: string | null) {
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

function changedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k))
        .sort((a, b) => a.localeCompare(b));
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                {label}
            </div>
            <div className="text-right text-sm font-medium">{value}</div>
        </div>
    );
}

export default function PositionDetails({
    position,
    companies,
    departments,
    recent_activity,
    can_view_audit,
}: {
    position: Position & { updated_at?: string };
    companies: Company[];
    departments: DepartmentOption[];
    recent_activity: ActivityItem[];
    can_view_audit: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<
        Record<number, boolean>
    >({});
    const form = useForm<PositionFormData>({
        company_id: position.company.id ?? '',
        department_id: position.department?.id ?? '',
        title: position.title ?? '',
        grade: position.grade ?? '',
        min_salary: position.min_salary ? String(position.min_salary) : '',
        max_salary: position.max_salary ? String(position.max_salary) : '',
        status: position.status ?? 'active',
    });

    return (
        <>
            <Head title={`Position • ${position.title}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={position.title}
                    description={`${position.company.name ?? '—'}${position.department?.name ? ` • ${position.department.name}` : ''}`}
                    backHref="/organization/positions"
                    backLabel="Back to positions"
                    actions={
                        <Button
                            className="h-11 rounded-xl px-5"
                            onClick={() => setOpen(true)}
                        >
                            Edit
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="glass-card lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="space-y-1">
                                <CardTitle className="text-xl font-bold tracking-tight">
                                    Overview
                                </CardTitle>
                                <div className="text-sm text-muted-foreground/80">
                                    Key position information.
                                </div>
                            </div>
                            <Badge
                                className={
                                    position.status === 'active'
                                        ? 'border border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
                                        : 'border border-border/80 bg-muted/60 text-muted-foreground dark:border-zinc-500/20 dark:bg-zinc-500/10 dark:text-zinc-200'
                                }
                            >
                                {position.status}
                            </Badge>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y divide-border dark:divide-white/5">
                                <Field
                                    label="Company"
                                    value={position.company.name ?? '—'}
                                />
                                <Field
                                    label="Department"
                                    value={
                                        position.department ? (
                                            <>
                                                {position.department.parent ? (
                                                    <span className="font-medium text-muted-foreground">
                                                        {
                                                            position.department
                                                                .parent.name
                                                        }{' '}
                                                        /{' '}
                                                    </span>
                                                ) : null}
                                                {position.department.name}
                                            </>
                                        ) : (
                                            '—'
                                        )
                                    }
                                />
                                <Field
                                    label="Grade"
                                    value={position.grade ?? '—'}
                                />
                                <Field
                                    label="Min Salary"
                                    value={position.min_salary ?? '—'}
                                />
                                <Field
                                    label="Max Salary"
                                    value={position.max_salary ?? '—'}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader>
                            <CardTitle className="text-xl font-bold tracking-tight">
                                Quick info
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <Users className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Employees
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {position.users_count ?? 0}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <BadgeDollarSign className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Grade
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {position.grade ?? '—'}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 p-4 dark:border-white/5 dark:bg-white/5">
                                <Crown className="h-5 w-5 text-primary" />
                                <div className="min-w-0">
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Status
                                    </div>
                                    <div className="truncate text-sm font-semibold">
                                        {position.status}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {can_view_audit ? (
                    <Card className="mt-8 glass-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border pb-4 dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Activity className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Recent activity
                                    </CardTitle>
                                    <div className="text-[10px] text-muted-foreground/50">
                                        Latest changes for this position.
                                    </div>
                                </div>
                            </div>
                            <Badge className="border-border bg-muted/50 font-mono text-xs text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                {recent_activity.length}
                            </Badge>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent_activity.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                                        <Activity className="h-5 w-5 text-muted-foreground/20" />
                                    </div>
                                    <p className="text-sm text-muted-foreground/50">
                                        No activity recorded yet.
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-border dark:divide-white/5">
                                    {recent_activity.map((a) => {
                                        const keys = changedKeys(
                                            a.old_values,
                                            a.new_values,
                                        );
                                        const isExpanded =
                                            expandedActivity[a.id] ?? false;
                                        const shown = isExpanded
                                            ? keys
                                            : keys.slice(0, 4);
                                        const showDescription =
                                            a.description
                                                .trim()
                                                .toLowerCase() !==
                                            (a.event ?? '')
                                                .trim()
                                                .toLowerCase();

                                        return (
                                            <div
                                                key={a.id}
                                                className="px-6 py-4 transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.015]"
                                            >
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="min-w-0 flex-1 space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                className={cn(
                                                                    'border px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase',
                                                                    eventColor(
                                                                        a.event,
                                                                    ),
                                                                )}
                                                            >
                                                                {a.event ??
                                                                    'event'}
                                                            </Badge>
                                                            <span className="text-sm font-semibold text-foreground/90">
                                                                {a.causer
                                                                    ?.name ??
                                                                    'System'}
                                                            </span>
                                                            {a.causer?.email ? (
                                                                <span className="text-xs text-muted-foreground/50">
                                                                    (
                                                                    {
                                                                        a.causer
                                                                            .email
                                                                    }
                                                                    )
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {showDescription ? (
                                                            <p className="text-xs text-muted-foreground/70">
                                                                {a.description}
                                                            </p>
                                                        ) : null}

                                                        {shown.length > 0 ? (
                                                            <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                                {shown.map(
                                                                    (k) => (
                                                                        <span
                                                                            key={
                                                                                k
                                                                            }
                                                                            className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5"
                                                                        >
                                                                            {titleCaseKey(
                                                                                k,
                                                                            )}
                                                                            :{' '}
                                                                            <span className="text-muted-foreground/70">
                                                                                {formatDisplayValue(
                                                                                    a
                                                                                        .old_values?.[
                                                                                        k
                                                                                    ],
                                                                                )}
                                                                            </span>{' '}
                                                                            →{' '}
                                                                            <span className="text-foreground/90">
                                                                                {formatDisplayValue(
                                                                                    a
                                                                                        .new_values?.[
                                                                                        k
                                                                                    ],
                                                                                )}
                                                                            </span>
                                                                        </span>
                                                                    ),
                                                                )}
                                                                {keys.length >
                                                                4 ? (
                                                                    <button
                                                                        type="button"
                                                                        className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground transition hover:bg-accent dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                                                        onClick={() =>
                                                                            setExpandedActivity(
                                                                                (
                                                                                    prev,
                                                                                ) => ({
                                                                                    ...prev,
                                                                                    [a.id]: !(
                                                                                        prev[
                                                                                            a
                                                                                                .id
                                                                                        ] ??
                                                                                        false
                                                                                    ),
                                                                                }),
                                                                            )
                                                                        }
                                                                    >
                                                                        {isExpanded
                                                                            ? 'Show less'
                                                                            : `+${keys.length - 4} more`}
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <div className="shrink-0 text-xs text-muted-foreground/50">
                                                        {formatDisplayDate(
                                                            a.created_at,
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                <PositionFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    position={position}
                    companies={companies}
                    departments={departments}
                    form={form}
                    onSubmit={() => {
                        form.put(`/organization/positions/${position.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}
