import { Head, useForm } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserFormSheet } from '@/features/organization/users/components/user-form-sheet';
import type { User, UserFormData } from '@/features/organization/users/types';

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

function formatActivityDate(value: string): string {
    const dt = new Date(value);

    if (Number.isNaN(dt.getTime())) {
        return value;
    }

    return dt.toLocaleString();
}

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

function changedKeys(oldValues: Record<string, unknown> | null, newValues: Record<string, unknown> | null): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k))
        .sort((a, b) => a.localeCompare(b));
}

export default function UserDetails({
    user,
    roles,
    recent_activity,
}: {
    user: User & { updated_at?: string };
    roles: { id: number; name: string }[];
    recent_activity: ActivityItem[];
}) {
    const [open, setOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});
    const form = useForm<UserFormData>({
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
        avatar: null,
        role_id: user.role?.id ?? '',
        status: user.status ?? 'active',
    });

    const statusClass =
        user.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : user.status === 'suspended'
              ? 'bg-amber-500/10 text-amber-200 border-amber-500/20'
              : 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20';

    return (
        <>
            <Head title={`User • ${user.name}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={user.name}
                    description={user.email}
                    backHref="/organization/users"
                    backLabel="Back to users"
                    actions={
                        <Button className="rounded-xl h-11 px-5" onClick={() => setOpen(true)}>
                            Edit
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-4">
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Avatar</div>
                                <div className="h-12 w-12 rounded-2xl bg-white/6 border border-white/10 overflow-hidden flex items-center justify-center text-foreground/80">
                                    {user.avatar ? (
                                        <img src={user.avatar} alt={user.name} className="h-full w-full object-cover" loading="lazy" />
                                    ) : (
                                        <div className="text-xs font-semibold text-muted-foreground/80">—</div>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Status</div>
                                <Badge className={`text-[10px] uppercase font-bold tracking-wider border ${statusClass}`}>{user.status}</Badge>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Company</div>
                                <div className="text-sm font-bold">{user.company?.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Role</div>
                                <div className="text-sm font-bold">{user.role?.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Last login</div>
                                <div className="text-sm font-bold">{user.last_login_at ?? '—'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card className="border-white/5 bg-white/5 mt-8">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="h-9 w-9 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-muted-foreground">
                                <Activity className="h-4 w-4" />
                            </div>
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Recent activity
                                </CardTitle>
                                <div className="text-xs text-muted-foreground/70">
                                    Latest changes for this user.
                                </div>
                            </div>
                        </div>
                        <Badge className="bg-white/5 text-muted-foreground border-white/10">
                            {recent_activity.length} items
                        </Badge>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {recent_activity.length === 0 ? (
                            <div className="rounded-xl border border-white/5 bg-white/5 p-10 text-center text-sm text-muted-foreground/80">
                                No recent activity yet.
                            </div>
                        ) : (
                            <div className="divide-y divide-white/5 rounded-xl border border-white/5 overflow-hidden">
                                {recent_activity.map((a) => {
                                    const keys = changedKeys(a.old_values, a.new_values);
                                    const isExpanded = expandedActivity[a.id] ?? false;
                                    const shown = isExpanded ? keys : keys.slice(0, 4);
                                    const showDescription =
                                        a.description.trim().toLowerCase() !== (a.event ?? '').trim().toLowerCase();

                                    return (
                                        <div key={a.id} className="px-4 py-4 sm:px-6">
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="min-w-0 space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                                            {a.event ?? 'event'}
                                                        </Badge>
                                                        <div className="text-sm font-medium">
                                                            {a.causer?.name ?? 'System'}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground/70">
                                                            {a.causer?.email ? `(${a.causer.email})` : ''}
                                                        </div>
                                                    </div>

                                                    {showDescription ? (
                                                        <div className="text-sm text-muted-foreground/90">
                                                            {a.description}
                                                        </div>
                                                    ) : null}

                                                    {shown.length > 0 ? (
                                                        <div className="flex flex-wrap gap-2 pt-1">
                                                            {shown.map((k) => (
                                                                <span
                                                                    key={k}
                                                                    className="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] text-muted-foreground"
                                                                >
                                                                    {titleCaseKey(k)}:{' '}
                                                                    <span className="text-muted-foreground/70">
                                                                        {formatValue(a.old_values?.[k])}
                                                                    </span>{' '}
                                                                    →{' '}
                                                                    <span className="text-foreground/90">
                                                                        {formatValue(a.new_values?.[k])}
                                                                    </span>
                                                                </span>
                                                            ))}
                                                            {keys.length > 4 ? (
                                                                <button
                                                                    type="button"
                                                                    className="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] text-muted-foreground hover:bg-white/10 transition"
                                                                    onClick={() =>
                                                                        setExpandedActivity((prev) => ({
                                                                            ...prev,
                                                                            [a.id]: !(prev[a.id] ?? false),
                                                                        }))
                                                                    }
                                                                >
                                                                    {isExpanded ? 'Show less' : `+${keys.length - 4} more`}
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>

                                                <div className="shrink-0 text-xs text-muted-foreground/70">
                                                    {formatActivityDate(a.created_at)}
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

