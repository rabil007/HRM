import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Company, Role, RoleFormData } from '@/features/organization/roles/types';

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
}: {
    role: Role & { updated_at?: string };
    company: (Company & { slug?: string }) | null;
    permissions: { id: number; name: string }[];
}) {
    const form = useForm<RoleFormData>({
        name: role.name ?? '',
    });

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
                </div>
            </Main>
        </>
    );
}

