import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowLeft,
    Building2,
    CalendarClock,
    ChevronDown,
    ChevronUp,
    Clock,
    Mail,
    Shield,
    User as UserIcon,
} from 'lucide-react';
import { useState } from 'react';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { UserFormSheet } from '@/features/organization/users/components/user-form-sheet';
import type {
    EmployeeForLinking,
    User,
    UserFormData,
} from '@/features/organization/users/types';
import {
    formatDisplayDate,
    formatDisplayDateTime,
    formatDisplayValue,
    formatActivityFieldLabel,
} from '@/lib/format-date';
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

function statusConfig(status: User['status']) {
    if (status === 'active') {
        return {
            label: 'Active',
            dot: 'bg-emerald-400',
            badge: 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-300',
        };
    }

    if (status === 'suspended') {
        return {
            label: 'Suspended',
            dot: 'bg-amber-400',
            badge: 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-300',
        };
    }

    return {
        label: 'Inactive',
        dot: 'bg-zinc-400',
        badge: 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:border-zinc-500/20',
    };
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

/** Single info row used in the details card */
function InfoRow({
    icon: Icon,
    label,
    value,
    valueNode,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value?: string;
    valueNode?: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-4 border-b border-border py-3.5 last:border-0 dark:border-white/5">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/40 dark:border-white/5 dark:bg-white/[0.04]">
                <Icon className="h-3.5 w-3.5 text-muted-foreground/60" />
            </div>
            <div className="w-28 shrink-0 text-sm font-medium text-muted-foreground/70">
                {label}
            </div>
            <div className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground/90">
                {valueNode ?? value ?? '—'}
            </div>
        </div>
    );
}

export default function UserDetails({
    user,
    roles,
    recent_activity,
    employees_for_linking,
    can_view_audit,
}: {
    user: User & { updated_at?: string };
    roles: { id: number; name: string }[];
    recent_activity: ActivityItem[];
    employees_for_linking: EmployeeForLinking[];
    can_view_audit: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<
        Record<number, boolean>
    >({});

    const form = useForm<UserFormData>({
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
        password_confirmation: '',
        avatar: null,
        use_employee_avatar: false,
        employee_id: user.linked_employee?.id ?? '',
        role_id: user.role?.id ?? '',
        status: user.status ?? 'active',
    });

    const status = statusConfig(user.status);

    /** Initials fallback */
    const initials = user.name
        .split(' ')
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

    return (
        <>
            <Head title={`User • ${user.name}`} />
            <Main>
                {/* ── Back nav ── */}
                <div className="mb-6">
                    <Link
                        href="/organization/users"
                        className="inline-flex items-center gap-2 text-xs font-semibold text-muted-foreground/60 transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        Back to users
                    </Link>
                </div>

                {/* ── Hero profile banner ── */}
                <div className="relative mb-6 overflow-hidden rounded-2xl border border-border bg-muted/30 dark:border-white/5 dark:bg-white/[0.03]">
                    {/* Glow orb */}
                    <div className="pointer-events-none absolute top-0 right-0 h-80 w-80 translate-x-1/3 -translate-y-1/2 rounded-full bg-primary/10 blur-[80px]" />

                    <div className="relative z-10 flex flex-col gap-6 px-8 py-7 sm:flex-row sm:items-center">
                        {/* Avatar */}
                        <div className="relative shrink-0">
                            <div className="flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl border-2 border-border bg-gradient-to-br from-primary/30 to-primary/10 shadow-xl dark:border-white/10">
                                {user.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="h-full w-full object-cover"
                                        loading="lazy"
                                    />
                                ) : (
                                    <span className="text-2xl font-black text-primary/80">
                                        {initials}
                                    </span>
                                )}
                            </div>
                            {/* Status dot */}
                            <span
                                className={cn(
                                    'absolute -right-1 -bottom-1 h-4 w-4 rounded-full border-2 border-background',
                                    status.dot,
                                )}
                            />
                        </div>

                        {/* Identity */}
                        <div className="min-w-0 flex-1">
                            <div className="mb-1 flex items-center gap-2">
                                <span className="flex h-1.5 w-1.5 animate-pulse rounded-full bg-primary" />
                                <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                                    Organization · User
                                </span>
                            </div>
                            <h1 className="truncate text-3xl font-extrabold tracking-tight text-foreground">
                                {user.name}
                            </h1>
                            <p className="mt-1 flex items-center gap-1.5 text-sm font-medium text-muted-foreground/70">
                                <Mail className="h-3.5 w-3.5" />
                                {user.email}
                            </p>
                        </div>

                        {/* Actions */}
                        <div className="flex shrink-0 items-center gap-3">
                            <Badge
                                className={cn(
                                    'border px-3 py-1 text-[10px] font-bold tracking-wider uppercase',
                                    status.badge,
                                )}
                            >
                                {status.label}
                            </Badge>
                            <Button
                                className="h-10 rounded-xl px-5"
                                onClick={() => setOpen(true)}
                            >
                                Edit user
                            </Button>
                        </div>
                    </div>
                </div>

                {/* ── Details grid ── */}
                <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Main details card */}
                    <Card className="border-border bg-card lg:col-span-2 dark:border-white/5 dark:bg-white/[0.03]">
                        <CardContent className="px-6 pt-5 pb-2">
                            <p className="mb-1 text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase">
                                Account details
                            </p>
                            <InfoRow
                                icon={Building2}
                                label="Company"
                                value={user.company?.name ?? '—'}
                            />
                            <InfoRow
                                icon={Shield}
                                label="Role"
                                value={user.role?.name ?? '—'}
                            />
                            <InfoRow
                                icon={UserIcon}
                                label="Linked employee"
                                value={user.linked_employee?.name ?? '—'}
                                valueNode={
                                    user.linked_employee ? (
                                        <span className="flex items-center gap-2">
                                            {user.linked_employee.image_url ? (
                                                <img
                                                    src={
                                                        user.linked_employee
                                                            .image_url
                                                    }
                                                    alt={
                                                        user.linked_employee
                                                            .name
                                                    }
                                                    className="h-5 w-5 rounded-full object-cover"
                                                />
                                            ) : null}
                                            <span>
                                                {user.linked_employee.name}
                                            </span>
                                            <span className="font-mono text-[10px] text-muted-foreground/50">
                                                #
                                                {
                                                    user.linked_employee
                                                        .employee_no
                                                }
                                            </span>
                                        </span>
                                    ) : undefined
                                }
                            />
                            <InfoRow
                                icon={Clock}
                                label="Last login"
                                value={formatDisplayDateTime(
                                    user.last_login_at,
                                )}
                            />
                            <InfoRow
                                icon={CalendarClock}
                                label="Created"
                                value={formatDisplayDate(user.created_at)}
                            />
                        </CardContent>
                    </Card>

                    {/* Quick stats sidebar */}
                    <div className="space-y-4">
                        <Card className="overflow-hidden border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                            <CardContent className="space-y-4 p-5">
                                <p className="text-[10px] font-bold tracking-widest text-muted-foreground/60 uppercase">
                                    Activity
                                </p>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground/70">
                                        Logged changes
                                    </span>
                                    <span className="text-2xl font-black text-foreground">
                                        {recent_activity.length}
                                    </span>
                                </div>
                                <div className="h-px bg-border dark:bg-white/5" />
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground/70">
                                        Status
                                    </span>
                                    <Badge
                                        className={cn(
                                            'border px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase',
                                            status.badge,
                                        )}
                                    >
                                        {status.label}
                                    </Badge>
                                </div>
                                {user.last_login_at ? (
                                    <>
                                        <div className="h-px bg-border dark:bg-white/5" />
                                        <div>
                                            <p className="mb-1 text-[10px] text-muted-foreground/60">
                                                Last seen
                                            </p>
                                            <p className="text-xs font-semibold text-foreground/80">
                                                {formatDisplayDateTime(
                                                    user.last_login_at,
                                                )}
                                            </p>
                                        </div>
                                    </>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Activity log ── */}
                {can_view_audit ? (
                    <Card className="border-border bg-card dark:border-white/5 dark:bg-white/[0.03]">
                        <div className="flex items-center justify-between border-b border-border px-6 py-5 dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Activity className="h-4 w-4" />
                                </div>
                                <div>
                                    <h2 className="text-base font-bold tracking-tight">
                                        Recent activity
                                    </h2>
                                    <p className="text-[10px] text-muted-foreground/50">
                                        Latest changes for this user
                                    </p>
                                </div>
                            </div>
                            <Badge className="border-border bg-muted/50 font-mono text-xs text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                {recent_activity.length}
                            </Badge>
                        </div>

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
                                                        {/* Row 1: event + causer */}
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

                                                        {/* Description */}
                                                        {showDescription ? (
                                                            <p className="text-xs text-muted-foreground/70">
                                                                {a.description}
                                                            </p>
                                                        ) : null}

                                                        {/* Changed fields */}
                                                        {shown.length > 0 ? (
                                                            <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                                {shown.map(
                                                                    (k) => (
                                                                        <span
                                                                            key={
                                                                                k
                                                                            }
                                                                            className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-2.5 py-1 text-[11px] text-muted-foreground/80 dark:border-white/8 dark:bg-white/[0.04]"
                                                                        >
                                                                            <span className="font-semibold text-foreground/60">
                                                                                {formatActivityFieldLabel(
                                                                                    k,
                                                                                )}

                                                                                :
                                                                            </span>
                                                                            <span className="line-through opacity-50">
                                                                                {formatDisplayValue(
                                                                                    a
                                                                                        .old_values?.[
                                                                                        k
                                                                                    ],
                                                                                )}
                                                                            </span>
                                                                            <span className="font-medium text-foreground/80">
                                                                                →{' '}
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
                                                                        className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-2.5 py-1 text-[11px] text-muted-foreground/60 transition-colors hover:bg-accent hover:text-foreground dark:border-white/8 dark:bg-white/[0.04] dark:hover:bg-white/[0.08]"
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
                                                                        {isExpanded ? (
                                                                            <>
                                                                                <ChevronUp className="h-3 w-3" />
                                                                                Show
                                                                                less
                                                                            </>
                                                                        ) : (
                                                                            <>
                                                                                <ChevronDown className="h-3 w-3" />

                                                                                +
                                                                                {keys.length -
                                                                                    4}{' '}
                                                                                more
                                                                            </>
                                                                        )}
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    {/* Timestamp */}
                                                    <div className="mt-0.5 shrink-0 font-mono text-[11px] whitespace-nowrap text-muted-foreground/40">
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

                <UserFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    user={user}
                    roles={roles}
                    employeesForLinking={employees_for_linking}
                    form={form}
                    onSubmit={() => {
                        form.put(`/organization/users/${user.id}`, {
                            preserveScroll: true,
                            forceFormData: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}
