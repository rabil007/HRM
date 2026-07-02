import { Head, router, useForm } from '@inertiajs/react';
import {
    Search,
    Shield,
    CheckCircle2,
    Circle,
    LayoutGrid,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import type {
    Company,
    Role,
    RoleFormData,
} from '@/features/organization/roles/types';

function normalizePermissions(value: string[]): string[] {
    return Array.from(
        new Set(value.map((p) => p.trim()).filter(Boolean)),
    ).sort();
}

function formatPermissionGroupLabel(segment: string): string {
    return segment.replace(/[-_]/g, ' ').toUpperCase();
}

const PERMISSION_LABEL_OVERRIDES: Record<string, string> = {
    'attendance.records.manage': 'Records: View All Employees',
};

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
    const [permissionView, setPermissionView] = useState<
        'all' | 'selected' | 'unselected'
    >('all');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        normalizePermissions(role.permissions ?? []),
    );

    const availablePermissions = useMemo(
        () => normalizePermissions(permissions.map((p) => p.name)),
        [permissions],
    );
    const selectedSet = useMemo(
        () => new Set(selectedPermissions),
        [selectedPermissions],
    );

    const formatPermissionName = (name: string) => {
        if (PERMISSION_LABEL_OVERRIDES[name]) {
            return PERMISSION_LABEL_OVERRIDES[name];
        }

        const parts = name.split('.');

        if (parts.length <= 1) {
            return name;
        }

        // Remove the group from the name for display if it's the first part
        const rest = parts.slice(1);

        return rest
            .map((p) =>
                p
                    .split(/[-_]/)
                    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' '),
            )
            .join(': ');
    };

    const grouped = useMemo(() => {
        const query = permissionQuery.trim().toLowerCase();
        const list = query
            ? availablePermissions.filter((p) =>
                  p.toLowerCase().includes(query),
              )
            : availablePermissions;

        // Map<MainGroup, Map<SubGroup, Permission[]>>
        const mainMap = new Map<string, Map<string, string[]>>();

        for (const permission of list) {
            const parts = permission.split('.');
            const mainGroup = formatPermissionGroupLabel(parts[0] || 'other');

            // Sub-group logic: take all parts between the first and the last
            let subGroup = 'GENERAL';

            if (parts.length > 2) {
                subGroup = parts
                    .slice(1, -1)
                    .map((p) => formatPermissionGroupLabel(p))
                    .join(' • ');
            } else if (parts.length === 2 && mainGroup === 'SETTINGS') {
                // Handle cases like settings.view
                subGroup = 'CORE';
            }

            if (!mainMap.has(mainGroup)) {
                mainMap.set(mainGroup, new Map());
            }

            const subMap = mainMap.get(mainGroup)!;

            if (!subMap.has(subGroup)) {
                subMap.set(subGroup, []);
            }

            subMap.get(subGroup)!.push(permission);
        }

        // Convert to sorted array structure
        return Array.from(mainMap.entries())
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([mainGroup, subMap]) => {
                const subGroups = Array.from(subMap.entries())
                    .sort(([a], [b]) => a.localeCompare(b))
                    .map(([name, items]) => [name, items.sort()] as const);

                return [mainGroup, subGroups] as const;
            });
    }, [availablePermissions, permissionQuery]);

    const initialGroup = grouped[0]?.[0] ?? null;
    const [activeGroup, setActiveGroup] = useState<string | null>(initialGroup);
    const effectiveActiveGroup = activeGroup ?? initialGroup;

    const togglePermission = (permission: string, next: boolean) => {
        if (next) {
            setSelectedPermissions((prev) =>
                normalizePermissions([...prev, permission]),
            );

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
                        <div className="flex items-center gap-3">
                            <Button
                                asChild
                                variant="outline"
                                className="h-11 rounded-xl border-border bg-card px-5 dark:border-white/10 dark:bg-white/5"
                            >
                                <a
                                    href={`/organization/users?role_id=${role.id}`}
                                >
                                    <Users className="mr-2 h-4 w-4" />
                                    View Users
                                </a>
                            </Button>
                            <Button
                                className="h-11 rounded-xl px-5"
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
                        </div>
                    }
                />

                <div className="flex flex-col gap-6">
                    {/* Top Action Bar */}
                    <Card className="border-border bg-card dark:border-white/5 dark:bg-white/5">
                        <CardContent className="flex flex-col items-center justify-between gap-6 p-4 md:flex-row">
                            <div className="w-full space-y-1.5 font-medium md:max-w-md">
                                <Label
                                    htmlFor="role-name"
                                    className="ml-1 text-[10px] tracking-widest text-muted-foreground/60 uppercase"
                                >
                                    Role Display Name
                                </Label>
                                <Input
                                    id="role-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Enter role name..."
                                    className="h-11 rounded-xl border-border bg-muted/50 px-4 text-base font-semibold transition-all focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                />
                                {form.errors.name ? (
                                    <div className="mt-1 text-xs font-medium text-destructive">
                                        {form.errors.name}
                                    </div>
                                ) : null}
                            </div>

                            <div className="flex w-full items-center gap-4 md:w-auto">
                                <div className="group relative flex-1 md:w-80">
                                    <Search className="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground/40 transition-colors group-focus-within:text-primary" />
                                    <Input
                                        value={permissionQuery}
                                        onChange={(e) =>
                                            setPermissionQuery(e.target.value)
                                        }
                                        placeholder="Search permissions..."
                                        className="h-11 rounded-xl border-border bg-muted/50 pl-11 transition-all focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                                    />
                                </div>
                                <div className="flex items-center gap-1.5 rounded-xl border border-border bg-muted/20 p-1 dark:border-white/5 dark:bg-white/[0.03]">
                                    {[
                                        {
                                            id: 'all',
                                            label: 'All',
                                            icon: LayoutGrid,
                                        },
                                        {
                                            id: 'selected',
                                            label: 'Selected',
                                            icon: CheckCircle2,
                                        },
                                        {
                                            id: 'unselected',
                                            label: 'Unselected',
                                            icon: Circle,
                                        },
                                    ].map((view) => (
                                        <Button
                                            key={view.id}
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className={`h-9 gap-2 rounded-lg px-4 text-xs font-bold transition-all ${
                                                permissionView === view.id
                                                    ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20'
                                                    : 'text-muted-foreground hover:bg-accent dark:hover:bg-white/5'
                                            }`}
                                            onClick={() =>
                                                setPermissionView(
                                                    view.id as any,
                                                )
                                            }
                                        >
                                            <view.icon className="h-3.5 w-3.5" />
                                            {view.label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Main Workspace */}
                    <div className="grid min-h-[600px] grid-cols-1 gap-6 lg:grid-cols-12">
                        {/* Sidebar Navigator */}
                        <aside className="space-y-4 lg:sticky lg:top-6 lg:col-span-3 lg:self-start">
                            <Card className="flex max-h-[calc(100vh-6rem)] flex-col overflow-hidden border-border bg-card dark:border-white/5 dark:bg-white/5">
                                <div className="flex items-center justify-between border-b border-border bg-muted/20 p-4 dark:border-white/5 dark:bg-white/[0.02]">
                                    <h3 className="text-xs font-bold tracking-widest text-muted-foreground uppercase">
                                        Categories
                                    </h3>
                                    <Badge
                                        variant="outline"
                                        className="border-border font-mono text-[10px] opacity-60 dark:border-white/5"
                                    >
                                        {grouped.length}
                                    </Badge>
                                </div>
                                <ScrollArea className="flex-1">
                                    <div className="space-y-1 p-2">
                                        {grouped.map(([group, subGroups]) => {
                                            const allItems = subGroups.flatMap(
                                                ([, items]) => items,
                                            );
                                            const selectedCount =
                                                allItems.filter((p) =>
                                                    selectedSet.has(p),
                                                ).length;
                                            const isActive =
                                                effectiveActiveGroup === group;
                                            const isComplete =
                                                selectedCount ===
                                                    allItems.length &&
                                                allItems.length > 0;

                                            return (
                                                <button
                                                    key={group}
                                                    onClick={() =>
                                                        setActiveGroup(group)
                                                    }
                                                    className={`group flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 transition-all ${
                                                        isActive
                                                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20'
                                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground dark:hover:bg-white/5'
                                                    }`}
                                                >
                                                    <div className="flex items-center gap-3 overflow-hidden">
                                                        <Shield
                                                            className={`h-4 w-4 flex-shrink-0 ${isActive ? 'text-primary-foreground' : 'text-primary'}`}
                                                        />
                                                        <span className="truncate text-sm font-bold tracking-tight">
                                                            {group}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        {isComplete && (
                                                            <CheckCircle2
                                                                className={`h-3.5 w-3.5 ${isActive ? 'text-primary-foreground' : 'text-primary'}`}
                                                            />
                                                        )}
                                                        <span
                                                            className={`font-mono text-[10px] ${isActive ? 'opacity-80' : 'opacity-40'}`}
                                                        >
                                                            {selectedCount}/
                                                            {allItems.length}
                                                        </span>
                                                    </div>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </ScrollArea>
                                <div className="border-t border-border bg-muted/20 p-3 dark:border-white/5 dark:bg-white/[0.02]">
                                    <Button
                                        variant="ghost"
                                        className="h-8 w-full text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase hover:text-primary"
                                        onClick={() =>
                                            setSelectedPermissions(
                                                availablePermissions,
                                            )
                                        }
                                    >
                                        Enable All Permissions
                                    </Button>
                                </div>
                            </Card>
                        </aside>

                        {/* Content Area */}
                        <main className="lg:col-span-9">
                            <Card className="flex h-full flex-col overflow-hidden border-border bg-card shadow-2xl dark:border-white/5 dark:bg-white/5">
                                {effectiveActiveGroup ? (
                                    <>
                                        {(() => {
                                            const groupData = grouped.find(
                                                ([g]) =>
                                                    g === effectiveActiveGroup,
                                            );

                                            if (!groupData) {
                                                return null;
                                            }

                                            const [group, subGroups] =
                                                groupData;

                                            const allItems = subGroups.flatMap(
                                                ([, items]) => items,
                                            );
                                            const selectedCount =
                                                allItems.filter((p) =>
                                                    selectedSet.has(p),
                                                ).length;
                                            const allSelected =
                                                selectedCount ===
                                                    allItems.length &&
                                                allItems.length > 0;

                                            return (
                                                <>
                                                    <div className="sticky top-0 z-10 flex items-center justify-between border-b border-border bg-background/80 bg-muted/20 p-6 backdrop-blur-md dark:border-white/5 dark:bg-white/[0.02]">
                                                        <div className="flex items-center gap-4">
                                                            <div className="flex h-10 w-10 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                                                <Shield className="h-5 w-5" />
                                                            </div>
                                                            <div>
                                                                <h2 className="text-lg font-bold tracking-tight text-foreground">
                                                                    {group}
                                                                </h2>
                                                                <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                                                    Manage
                                                                    permissions
                                                                    for the{' '}
                                                                    {group.toLowerCase()}{' '}
                                                                    module
                                                                    <span className="mx-1 inline-block h-1 w-1 rounded-full bg-muted-foreground/40" />
                                                                    {
                                                                        selectedCount
                                                                    }{' '}
                                                                    selected
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="rounded-xl border-border bg-muted/50 text-xs font-bold hover:bg-accent dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                                                                onClick={() => {
                                                                    if (
                                                                        allSelected
                                                                    ) {
                                                                        const remove =
                                                                            new Set(
                                                                                allItems,
                                                                            );
                                                                        setSelectedPermissions(
                                                                            (
                                                                                prev,
                                                                            ) =>
                                                                                prev.filter(
                                                                                    (
                                                                                        p,
                                                                                    ) =>
                                                                                        !remove.has(
                                                                                            p,
                                                                                        ),
                                                                                ),
                                                                        );

                                                                        return;
                                                                    }

                                                                    setSelectedPermissions(
                                                                        (
                                                                            prev,
                                                                        ) =>
                                                                            normalizePermissions(
                                                                                [
                                                                                    ...prev,
                                                                                    ...allItems,
                                                                                ],
                                                                            ),
                                                                    );
                                                                }}
                                                            >
                                                                {allSelected
                                                                    ? 'Unselect All'
                                                                    : 'Select All'}
                                                            </Button>
                                                        </div>
                                                    </div>
                                                    <ScrollArea className="flex-1">
                                                        <div className="space-y-12 p-8">
                                                            {subGroups.map(
                                                                ([
                                                                    subName,
                                                                    items,
                                                                ]) => {
                                                                    const subSelectedCount =
                                                                        items.filter(
                                                                            (
                                                                                p,
                                                                            ) =>
                                                                                selectedSet.has(
                                                                                    p,
                                                                                ),
                                                                        ).length;
                                                                    const subAllSelected =
                                                                        subSelectedCount ===
                                                                            items.length &&
                                                                        items.length >
                                                                            0;

                                                                    return (
                                                                        <div
                                                                            key={
                                                                                subName
                                                                            }
                                                                            className="space-y-6"
                                                                        >
                                                                            <div className="group/sub flex items-center justify-between">
                                                                                <div className="flex items-center gap-3">
                                                                                    <div className="h-6 w-1 rounded-full bg-primary" />
                                                                                    <div>
                                                                                        <h4 className="text-sm font-bold tracking-widest text-foreground uppercase">
                                                                                            {
                                                                                                subName
                                                                                            }
                                                                                        </h4>
                                                                                        <p className="text-[10px] font-medium tracking-wider text-muted-foreground/40">
                                                                                            {
                                                                                                subSelectedCount
                                                                                            }{' '}
                                                                                            of{' '}
                                                                                            {
                                                                                                items.length
                                                                                            }{' '}
                                                                                            Selected
                                                                                        </p>
                                                                                    </div>
                                                                                </div>
                                                                                <Button
                                                                                    variant="ghost"
                                                                                    size="sm"
                                                                                    className="h-8 rounded-lg px-3 text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase opacity-0 transition-opacity group-hover/sub:opacity-100 hover:bg-primary/5 hover:text-primary"
                                                                                    onClick={() => {
                                                                                        if (
                                                                                            subAllSelected
                                                                                        ) {
                                                                                            const remove =
                                                                                                new Set(
                                                                                                    items,
                                                                                                );
                                                                                            setSelectedPermissions(
                                                                                                (
                                                                                                    prev,
                                                                                                ) =>
                                                                                                    prev.filter(
                                                                                                        (
                                                                                                            p,
                                                                                                        ) =>
                                                                                                            !remove.has(
                                                                                                                p,
                                                                                                            ),
                                                                                                    ),
                                                                                            );

                                                                                            return;
                                                                                        }

                                                                                        setSelectedPermissions(
                                                                                            (
                                                                                                prev,
                                                                                            ) =>
                                                                                                normalizePermissions(
                                                                                                    [
                                                                                                        ...prev,
                                                                                                        ...items,
                                                                                                    ],
                                                                                                ),
                                                                                        );
                                                                                    }}
                                                                                >
                                                                                    {subAllSelected
                                                                                        ? 'Unselect'
                                                                                        : 'Select'}{' '}
                                                                                    Group
                                                                                </Button>
                                                                            </div>

                                                                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                                                                {items.map(
                                                                                    (
                                                                                        permission,
                                                                                    ) => {
                                                                                        const checked =
                                                                                            selectedSet.has(
                                                                                                permission,
                                                                                            );

                                                                                        return (
                                                                                            <label
                                                                                                key={
                                                                                                    permission
                                                                                                }
                                                                                                className={`flex cursor-pointer items-center gap-4 rounded-2xl border p-4 transition-all ${
                                                                                                    checked
                                                                                                        ? 'border-primary/20 bg-primary/[0.08] shadow-lg ring-1 shadow-primary/[0.03] ring-primary/10'
                                                                                                        : 'border-border bg-muted/20 hover:border-border hover:bg-muted/40 dark:border-white/5 dark:bg-white/[0.02] dark:hover:border-white/10 dark:hover:bg-white/[0.04]'
                                                                                                }`}
                                                                                            >
                                                                                                <Checkbox
                                                                                                    checked={
                                                                                                        checked
                                                                                                    }
                                                                                                    onCheckedChange={(
                                                                                                        value,
                                                                                                    ) =>
                                                                                                        togglePermission(
                                                                                                            permission,
                                                                                                            Boolean(
                                                                                                                value,
                                                                                                            ),
                                                                                                        )
                                                                                                    }
                                                                                                    className="h-5 w-5 border-border data-[state=checked]:border-primary data-[state=checked]:bg-primary dark:border-white/10"
                                                                                                />
                                                                                                <div className="flex-1 overflow-hidden">
                                                                                                    <p
                                                                                                        className={`truncate text-sm font-bold tracking-tight ${checked ? 'text-primary' : 'text-foreground/80'}`}
                                                                                                    >
                                                                                                        {formatPermissionName(
                                                                                                            permission,
                                                                                                        )}
                                                                                                    </p>
                                                                                                    <p className="mt-0.5 truncate text-[10px] font-medium tracking-tighter text-muted-foreground/40 uppercase">
                                                                                                        {permission
                                                                                                            .split(
                                                                                                                '.',
                                                                                                            )
                                                                                                            .pop()
                                                                                                            ?.replace(
                                                                                                                /-/g,
                                                                                                                ' ',
                                                                                                            )}{' '}
                                                                                                        Access
                                                                                                    </p>
                                                                                                </div>
                                                                                            </label>
                                                                                        );
                                                                                    },
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    </ScrollArea>
                                                </>
                                            );
                                        })()}
                                    </>
                                ) : (
                                    <div className="flex flex-1 flex-col items-center justify-center p-12 text-center">
                                        <div className="mb-6 flex h-20 w-20 items-center justify-center rounded-3xl border border-dashed border-border bg-muted/50 transition-transform group-hover:scale-110 dark:border-white/10 dark:bg-white/5">
                                            <Shield className="h-10 w-10 text-muted-foreground/20" />
                                        </div>
                                        <h3 className="mb-2 text-xl font-bold text-foreground">
                                            Select a Category
                                        </h3>
                                        <p className="mx-auto max-w-sm text-sm leading-relaxed text-muted-foreground">
                                            Choose a module from the left
                                            categories to manage its specific
                                            access permissions for this role.
                                        </p>
                                    </div>
                                )}
                            </Card>
                        </main>
                    </div>
                </div>
            </Main>
        </>
    );
}
