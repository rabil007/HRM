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
import type { EmployeeForLinking, User, UserFormData } from '@/features/organization/users/types';
import { formatDisplayDate, formatDisplayDateTime, formatDisplayValue } from '@/lib/format-date';
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
            badge: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20',
        };
    }

    if (status === 'suspended') {
        return {
            label: 'Suspended',
            dot: 'bg-amber-400',
            badge: 'bg-amber-500/10 text-amber-300 border-amber-500/20',
        };
    }

    return {
        label: 'Inactive',
        dot: 'bg-zinc-400',
        badge: 'bg-zinc-500/10 text-zinc-300 border-zinc-500/20',
    };
}

function eventColor(event: string | null) {
    switch (event?.toLowerCase()) {
        case 'created':
            return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
        case 'updated':
            return 'bg-sky-500/10 text-sky-400 border-sky-500/20';
        case 'deleted':
            return 'bg-red-500/10 text-red-400 border-red-500/20';
        default:
            return 'bg-white/5 text-muted-foreground border-white/10';
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
        <div className="flex items-center gap-4 py-3.5 border-b border-white/5 last:border-0">
            <div className="w-8 h-8 rounded-xl bg-white/[0.04] border border-white/5 flex items-center justify-center shrink-0">
                <Icon className="w-3.5 h-3.5 text-muted-foreground/60" />
            </div>
            <div className="text-sm text-muted-foreground/70 font-medium w-28 shrink-0">{label}</div>
            <div className="text-sm font-semibold text-foreground/90 min-w-0 truncate flex-1">
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
}: {
    user: User & { updated_at?: string };
    roles: { id: number; name: string }[];
    recent_activity: ActivityItem[];
    employees_for_linking: EmployeeForLinking[];
}) {
    const [open, setOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    const form = useForm<UserFormData>({
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
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
                        className="inline-flex items-center gap-2 text-xs font-semibold text-muted-foreground/60 hover:text-foreground transition-colors"
                    >
                        <ArrowLeft className="w-3.5 h-3.5" />
                        Back to users
                    </Link>
                </div>

                {/* ── Hero profile banner ── */}
                <div className="relative rounded-2xl border border-white/5 bg-white/[0.03] overflow-hidden mb-6">
                    {/* Glow orb */}
                    <div className="absolute top-0 right-0 w-80 h-80 bg-primary/10 blur-[80px] rounded-full -translate-y-1/2 translate-x-1/3 pointer-events-none" />

                    <div className="relative z-10 flex flex-col sm:flex-row sm:items-center gap-6 px-8 py-7">
                        {/* Avatar */}
                        <div className="relative shrink-0">
                            <div className="w-20 h-20 rounded-2xl border-2 border-white/10 overflow-hidden bg-gradient-to-br from-primary/30 to-primary/10 flex items-center justify-center shadow-xl">
                                {user.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="w-full h-full object-cover"
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
                                    'absolute -bottom-1 -right-1 w-4 h-4 rounded-full border-2 border-background',
                                    status.dot,
                                )}
                            />
                        </div>

                        {/* Identity */}
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-1">
                                <span className="flex h-1.5 w-1.5 rounded-full bg-primary animate-pulse" />
                                <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
                                    Organization · User
                                </span>
                            </div>
                            <h1 className="text-3xl font-extrabold tracking-tight text-foreground truncate">
                                {user.name}
                            </h1>
                            <p className="text-sm text-muted-foreground/70 font-medium mt-1 flex items-center gap-1.5">
                                <Mail className="w-3.5 h-3.5" />
                                {user.email}
                            </p>
                        </div>

                        {/* Actions */}
                        <div className="flex items-center gap-3 shrink-0">
                            <Badge
                                className={cn(
                                    'px-3 py-1 text-[10px] uppercase font-bold tracking-wider border',
                                    status.badge,
                                )}
                            >
                                {status.label}
                            </Badge>
                            <Button
                                className="rounded-xl h-10 px-5"
                                onClick={() => setOpen(true)}
                            >
                                Edit user
                            </Button>
                        </div>
                    </div>
                </div>

                {/* ── Details grid ── */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    {/* Main details card */}
                    <Card className="border-white/5 bg-white/[0.03] lg:col-span-2">
                        <CardContent className="px-6 pt-5 pb-2">
                            <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40 mb-1">
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
                                                    src={user.linked_employee.image_url}
                                                    alt={user.linked_employee.name}
                                                    className="w-5 h-5 rounded-full object-cover"
                                                />
                                            ) : null}
                                            <span>{user.linked_employee.name}</span>
                                            <span className="text-[10px] text-muted-foreground/50 font-mono">
                                                #{user.linked_employee.employee_no}
                                            </span>
                                        </span>
                                    ) : undefined
                                }
                            />
                            <InfoRow
                                icon={Clock}
                                label="Last login"
                                value={formatDisplayDateTime(user.last_login_at)}
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
                        <Card className="border-white/5 bg-white/[0.03] overflow-hidden">
                            <CardContent className="p-5 space-y-4">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40">
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
                                <div className="h-px bg-white/5" />
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground/70">Status</span>
                                    <Badge
                                        className={cn(
                                            'px-2 py-0.5 text-[10px] uppercase font-bold tracking-wider border',
                                            status.badge,
                                        )}
                                    >
                                        {status.label}
                                    </Badge>
                                </div>
                                {user.last_login_at ? (
                                    <>
                                        <div className="h-px bg-white/5" />
                                        <div>
                                            <p className="text-[10px] text-muted-foreground/40 mb-1">
                                                Last seen
                                            </p>
                                            <p className="text-xs font-semibold text-foreground/80">
                                                {formatDisplayDateTime(user.last_login_at)}
                                            </p>
                                        </div>
                                    </>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Activity log ── */}
                <Card className="border-white/5 bg-white/[0.03]">
                    <div className="px-6 py-5 border-b border-white/5 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                                <Activity className="w-4 h-4" />
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
                        <Badge className="bg-white/5 text-muted-foreground border-white/10 font-mono text-xs">
                            {recent_activity.length}
                        </Badge>
                    </div>

                    <CardContent className="p-0">
                        {recent_activity.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 text-center">
                                <div className="w-12 h-12 rounded-2xl bg-white/[0.03] border border-dashed border-white/10 flex items-center justify-center mb-3">
                                    <Activity className="w-5 h-5 text-muted-foreground/20" />
                                </div>
                                <p className="text-sm text-muted-foreground/50">
                                    No activity recorded yet.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-white/5">
                                {recent_activity.map((a) => {
                                    const keys = changedKeys(a.old_values, a.new_values);
                                    const isExpanded = expandedActivity[a.id] ?? false;
                                    const shown = isExpanded ? keys : keys.slice(0, 4);
                                    const showDescription =
                                        a.description.trim().toLowerCase() !==
                                        (a.event ?? '').trim().toLowerCase();

                                    return (
                                        <div key={a.id} className="px-6 py-4 hover:bg-white/[0.015] transition-colors">
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="min-w-0 space-y-2 flex-1">
                                                    {/* Row 1: event + causer */}
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge
                                                            className={cn(
                                                                'text-[10px] uppercase font-bold tracking-wider border px-2 py-0.5',
                                                                eventColor(a.event),
                                                            )}
                                                        >
                                                            {a.event ?? 'event'}
                                                        </Badge>
                                                        <span className="text-sm font-semibold text-foreground/90">
                                                            {a.causer?.name ?? 'System'}
                                                        </span>
                                                        {a.causer?.email ? (
                                                            <span className="text-xs text-muted-foreground/50">
                                                                ({a.causer.email})
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
                                                            {shown.map((k) => (
                                                                <span
                                                                    key={k}
                                                                    className="inline-flex items-center gap-1 rounded-lg border border-white/8 bg-white/[0.04] px-2.5 py-1 text-[11px] text-muted-foreground/80"
                                                                >
                                                                    <span className="font-semibold text-foreground/60">
                                                                        {titleCaseKey(k)}:
                                                                    </span>
                                                                    <span className="line-through opacity-50">
                                                                        {formatDisplayValue(
                                                                            a.old_values?.[k],
                                                                        )}
                                                                    </span>
                                                                    <span className="text-foreground/80 font-medium">
                                                                        →{' '}
                                                                        {formatDisplayValue(
                                                                            a.new_values?.[k],
                                                                        )}
                                                                    </span>
                                                                </span>
                                                            ))}
                                                            {keys.length > 4 ? (
                                                                <button
                                                                    type="button"
                                                                    className="inline-flex items-center gap-1 rounded-lg border border-white/8 bg-white/[0.04] px-2.5 py-1 text-[11px] text-muted-foreground/60 hover:bg-white/[0.08] hover:text-foreground transition-colors"
                                                                    onClick={() =>
                                                                        setExpandedActivity(
                                                                            (prev) => ({
                                                                                ...prev,
                                                                                [a.id]: !(
                                                                                    prev[a.id] ??
                                                                                    false
                                                                                ),
                                                                            }),
                                                                        )
                                                                    }
                                                                >
                                                                    {isExpanded ? (
                                                                        <>
                                                                            <ChevronUp className="w-3 h-3" />
                                                                            Show less
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <ChevronDown className="w-3 h-3" />
                                                                            +{keys.length - 4} more
                                                                        </>
                                                                    )}
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>

                                                {/* Timestamp */}
                                                <div className="shrink-0 text-[11px] text-muted-foreground/40 font-mono mt-0.5 whitespace-nowrap">
                                                    {formatDisplayDate(a.created_at)}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

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
