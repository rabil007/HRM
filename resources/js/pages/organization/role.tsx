import { Head, router, useForm } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Company, Role, RoleFormData } from '@/features/organization/roles/types';

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

function normalizePermissions(value: string[]): string[] {
    return Array.from(
        new Set(
            value
                .map((p) => p.trim())
                .filter(Boolean),
        ),
    ).sort();
}

export default function RoleDetails({
    role,
    company,
    permissions,
    recent_activity,
}: {
    role: Role & { updated_at?: string };
    company: (Company & { slug?: string }) | null;
    permissions: { id: number; name: string }[];
    recent_activity: ActivityItem[];
}) {
    const form = useForm<RoleFormData>({
        name: role.name ?? '',
    });
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    const [permissionQuery, setPermissionQuery] = useState('');
    const [permissionView, setPermissionView] = useState<'all' | 'selected' | 'unselected'>('all');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(normalizePermissions(role.permissions ?? []));

    const availablePermissions = useMemo(() => normalizePermissions(permissions.map((p) => p.name)), [permissions]);
    const selectedSet = useMemo(() => new Set(selectedPermissions), [selectedPermissions]);

    const grouped = useMemo(() => {
        const query = permissionQuery.trim().toLowerCase();
        const list = query ? availablePermissions.filter((p) => p.toLowerCase().includes(query)) : availablePermissions;
        const map = new Map<string, string[]>();

        for (const permission of list) {
            const [rawGroup] = permission.split('.');
            const group = (rawGroup || 'other').toUpperCase();
            const isSelected = selectedSet.has(permission);

            if (permissionView === 'selected' && !isSelected) {
                continue;
            }

            if (permissionView === 'unselected' && isSelected) {
                continue;
            }

            map.set(group, [...(map.get(group) ?? []), permission]);
        }

        return Array.from(map.entries())
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([group, items]) => [group, items.sort()] as const);
    }, [availablePermissions, permissionQuery, permissionView, selectedSet]);

    const togglePermission = (permission: string, next: boolean) => {
        if (next) {
            setSelectedPermissions((prev) => normalizePermissions([...prev, permission]));

            return;
        }

        setSelectedPermissions((prev) => prev.filter((p) => p !== permission));
    };

    return (
        <>
            <Head title={`Role • ${role.name}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={role.name}
                    description={company?.name ?? '—'}
                    backHref="/organization/roles"
                    backLabel="Back to roles"
                    actions={
                        <Button
                            className="rounded-xl h-11 px-5"
                            onClick={() => {
                                router.put(
                                    `/organization/roles/${role.id}`,
                                    {
                                        name: form.data.name,
                                        permissions: selectedPermissions,
                                    },
                                    {
                                        preserveScroll: true,
                                    },
                                );
                            }}
                            disabled={form.processing}
                        >
                            Save
                        </Button>
                    }
                />

                <div className="space-y-6">
                    <Card className="border-white/5 bg-white/5 w-full">
                        <CardContent className="p-6 space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="role-name" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Name
                                </Label>
                                <Input
                                    id="role-name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                />
                                {form.errors.name ? <div className="text-xs font-medium text-destructive">{form.errors.name}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-semibold text-muted-foreground/80">Permissions</div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant={permissionView === 'all' ? 'default' : 'secondary'}
                                            className={
                                                permissionView === 'all'
                                                    ? 'rounded-xl h-9 px-3'
                                                    : 'rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10'
                                            }
                                            onClick={() => setPermissionView('all')}
                                        >
                                            All
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={permissionView === 'selected' ? 'default' : 'secondary'}
                                            className={
                                                permissionView === 'selected'
                                                    ? 'rounded-xl h-9 px-3'
                                                    : 'rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10'
                                            }
                                            onClick={() => setPermissionView('selected')}
                                        >
                                            Selected
                                        </Button>
                                        <Button
                                            type="button"
                                            variant={permissionView === 'unselected' ? 'default' : 'secondary'}
                                            className={
                                                permissionView === 'unselected'
                                                    ? 'rounded-xl h-9 px-3'
                                                    : 'rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10'
                                            }
                                            onClick={() => setPermissionView('unselected')}
                                        >
                                            Unselected
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10"
                                            onClick={() => setSelectedPermissions([])}
                                        >
                                            Clear
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="rounded-xl h-9 px-3 border border-white/5 bg-white/5 hover:bg-white/10"
                                            onClick={() => setSelectedPermissions(availablePermissions)}
                                        >
                                            Select all
                                        </Button>
                                    </div>
                                </div>

                                <Input
                                    value={permissionQuery}
                                    onChange={(e) => setPermissionQuery(e.target.value)}
                                    placeholder="Search permissions..."
                                    className="rounded-xl border-white/10 bg-white/5 focus-visible:ring-primary/40 h-11 transition-all"
                                />

                                <div className="rounded-xl border border-white/10 bg-white/5 p-3 space-y-4 max-h-[70vh] overflow-y-auto">
                                    {grouped.length ? (
                                        grouped.map(([group, items]) => {
                                            const selectedCount = items.filter((p) => selectedSet.has(p)).length;
                                            const allSelected = selectedCount === items.length && items.length > 0;

                                            const sortedItems = [...items].sort((a, b) => {
                                                const aSelected = selectedSet.has(a);
                                                const bSelected = selectedSet.has(b);

                                                if (aSelected !== bSelected) {
                                                    return aSelected ? -1 : 1;
                                                }

                                                return a.localeCompare(b);
                                            });

                                            return (
                                                <div key={group} className="space-y-2">
                                                    <div className="flex items-center justify-between gap-3">
                                                        <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                                            {group}{' '}
                                                            <span className="ml-1 normal-case text-[11px] text-muted-foreground/60">
                                                                ({selectedCount}/{items.length})
                                                            </span>
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            className="rounded-xl h-8 px-3 text-xs text-muted-foreground hover:bg-white/10"
                                                            onClick={() => {
                                                                if (allSelected) {
                                                                    const remove = new Set(items);
                                                                    setSelectedPermissions((prev) => prev.filter((p) => !remove.has(p)));

                                                                    return;
                                                                }

                                                                setSelectedPermissions((prev) => normalizePermissions([...prev, ...items]));
                                                            }}
                                                        >
                                                            {allSelected ? 'Unselect' : 'Select'} all
                                                        </Button>
                                                    </div>

                                                    <div className="grid gap-2">
                                                        {sortedItems.map((permission) => {
                                                            const checked = selectedSet.has(permission);

                                                            return (
                                                                <label key={permission} className="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-white/5">
                                                                    <Checkbox
                                                                        checked={checked}
                                                                        onCheckedChange={(value) => togglePermission(permission, Boolean(value))}
                                                                    />
                                                                    <span className="text-sm font-medium text-muted-foreground/90">{permission}</span>
                                                                </label>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            );
                                        })
                                    ) : (
                                        <div className="text-sm text-muted-foreground/80">No permissions found.</div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5">
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
                                        Latest changes for this role.
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
                                                                        {titleCaseKey(k)}{' '}
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
                </div>
            </Main>
        </>
    );
}

