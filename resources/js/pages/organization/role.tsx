import { Head, useForm } from '@inertiajs/react';
import {
    Search,
    Shield,
    CheckCircle2,
    Circle,
    LayoutGrid,
    Users,
    ChevronRight,
    Building2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    applyDepartmentToggle,
    flattenDepartmentTreeIds,
    getDepartmentCheckState,
} from '@/features/organization/crew-planning/lib/department-tree';
import type {
    Company,
    PermissionOption,
    PlanningDepartmentNode,
    Role,
    RoleFormData,
} from '@/features/organization/roles/types';
import { cn } from '@/lib/utils';
import { resolveEffectiveActiveGroup } from '@/pages/organization/_lib/role-permission-active-group';
import { resolvePermissionGroups } from '@/pages/organization/_lib/role-permission-groups';
import { permissionMatchesQuery } from '@/pages/organization/_lib/role-permission-search';

function normalizePermissions(value: string[]): string[] {
    return Array.from(
        new Set(value.map((p) => p.trim()).filter(Boolean)),
    ).sort();
}

function DepartmentTreeNodeRow({
    node,
    depth,
    selectedIds,
    onToggle,
    disabled = false,
}: {
    node: PlanningDepartmentNode;
    depth: number;
    selectedIds: Set<number>;
    onToggle: (node: PlanningDepartmentNode, checked: boolean) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const checkState = getDepartmentCheckState(node, selectedIds);
    const hasChildren = node.children.length > 0;

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <div
                className={cn(
                    'group flex items-center gap-2 rounded-xl border px-3 py-2 transition-all',
                    depth === 0
                        ? 'border-border/70 bg-card/80 shadow-xs hover:border-primary/25'
                        : 'mt-1.5 border-transparent bg-muted/20 hover:border-border/60 hover:bg-muted/40',
                )}
                style={{ marginLeft: depth * 16 }}
            >
                {hasChildren ? (
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
                            aria-label={`Toggle ${node.name}`}
                        >
                            <ChevronRight
                                className={cn(
                                    'h-3.5 w-3.5 transition-transform',
                                    open && 'rotate-90',
                                )}
                            />
                        </button>
                    </CollapsibleTrigger>
                ) : (
                    <span className="inline-flex h-6 w-6 shrink-0" />
                )}

                <label
                    className={cn(
                        'flex min-w-0 flex-1 items-center gap-3 select-none',
                        disabled
                            ? 'cursor-not-allowed opacity-60'
                            : 'cursor-pointer',
                    )}
                >
                    <Checkbox
                        disabled={disabled}
                        checked={
                            checkState === 'indeterminate'
                                ? 'indeterminate'
                                : checkState === 'checked'
                        }
                        onCheckedChange={(value) =>
                            onToggle(node, value === true)
                        }
                    />
                    <span
                        className={cn(
                            'truncate text-sm',
                            depth === 0 ? 'font-semibold' : 'font-medium',
                        )}
                    >
                        {node.name}
                    </span>
                </label>
            </div>

            {hasChildren ? (
                <CollapsibleContent>
                    {node.children.map((child) => (
                        <DepartmentTreeNodeRow
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            selectedIds={selectedIds}
                            onToggle={onToggle}
                            disabled={disabled}
                        />
                    ))}
                </CollapsibleContent>
            ) : null}
        </Collapsible>
    );
}

export default function RoleDetails({
    role,
    company,
    permissions,
    department_tree = [],
}: {
    role: Role & {
        updated_at?: string;
        employee_visibility_scope?: 'all' | 'selected_departments';
        department_ids?: number[];
    };
    company: (Company & { slug?: string }) | null;
    permissions: PermissionOption[];
    department_tree?: PlanningDepartmentNode[];
}) {
    const isOwner = role.name === 'Owner';
    const form = useForm<RoleFormData>({
        name: role.name ?? '',
        employee_visibility_scope: isOwner
            ? 'all'
            : (role.employee_visibility_scope ?? 'all'),
        department_ids: isOwner ? [] : (role.department_ids ?? []),
    });

    const [visibilityScope, setVisibilityScope] = useState<
        'all' | 'selected_departments'
    >(isOwner ? 'all' : (role.employee_visibility_scope ?? 'all'));
    const [selectedDepartmentIds, setSelectedDepartmentIds] = useState<
        number[]
    >(role.department_ids ?? []);

    const selectedDeptSet = useMemo(
        () => new Set(selectedDepartmentIds),
        [selectedDepartmentIds],
    );
    const allDepartmentIds = useMemo(
        () => flattenDepartmentTreeIds(department_tree),
        [department_tree],
    );

    const toggleDepartment = (
        node: PlanningDepartmentNode,
        checked: boolean,
    ): void => {
        if (isOwner) {
            return;
        }

        setSelectedDepartmentIds((prev) =>
            applyDepartmentToggle(prev, node, checked),
        );
    };

    const [permissionQuery, setPermissionQuery] = useState('');
    const [permissionView, setPermissionView] = useState<
        'all' | 'selected' | 'unselected'
    >('all');
    const [selectedPermissions, setSelectedPermissions] = useState<string[]>(
        normalizePermissions(role.permissions ?? []),
    );

    const availablePermissions = useMemo(
        () =>
            [...permissions].sort((left, right) =>
                left.label.localeCompare(right.label),
            ),
        [permissions],
    );

    const availablePermissionNames = useMemo(
        () =>
            normalizePermissions(
                permissions.map((permission) => permission.name),
            ),
        [permissions],
    );

    const selectedSet = useMemo(
        () => new Set(selectedPermissions),
        [selectedPermissions],
    );

    const grouped = useMemo(() => {
        const list = availablePermissions.filter((permission) => {
            if (!permissionMatchesQuery(permission, permissionQuery)) {
                return false;
            }

            const checked = selectedSet.has(permission.name);

            if (permissionView === 'selected') {
                return checked;
            }

            if (permissionView === 'unselected') {
                return !checked;
            }

            return true;
        });

        const mainMap = new Map<string, Map<string, PermissionOption[]>>();

        for (const permission of list) {
            const { mainGroup, subGroup } = resolvePermissionGroups(
                permission.name,
                permission.group,
            );

            if (!mainMap.has(mainGroup)) {
                mainMap.set(mainGroup, new Map());
            }

            const subMap = mainMap.get(mainGroup)!;

            if (!subMap.has(subGroup)) {
                subMap.set(subGroup, []);
            }

            subMap.get(subGroup)!.push(permission);
        }

        return Array.from(mainMap.entries())
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([mainGroup, subMap]) => {
                const subGroups = Array.from(subMap.entries())
                    .sort(([a], [b]) => a.localeCompare(b))
                    .map(
                        ([name, items]) =>
                            [
                                name,
                                [...items].sort((left, right) =>
                                    left.label.localeCompare(right.label),
                                ),
                            ] as const,
                    );

                return [mainGroup, subGroups] as const;
            });
    }, [availablePermissions, permissionQuery, permissionView, selectedSet]);

    const [activeGroup, setActiveGroup] = useState<string | null>(
        () => grouped[0]?.[0] ?? null,
    );
    const effectiveActiveGroup = useMemo(
        () => resolveEffectiveActiveGroup(grouped, activeGroup),
        [grouped, activeGroup],
    );

    const togglePermission = (permission: string, next: boolean) => {
        if (next) {
            setSelectedPermissions((prev) =>
                normalizePermissions([...prev, permission]),
            );

            return;
        }

        setSelectedPermissions((prev) => prev.filter((p) => p !== permission));
    };

    const submit = (): void => {
        form.transform(() => ({
            name: form.data.name,
            permissions: selectedPermissions,
            employee_visibility_scope: isOwner ? 'all' : visibilityScope,
            department_ids:
                isOwner || visibilityScope === 'all'
                    ? []
                    : selectedDepartmentIds,
        }));

        form.put(`/organization/roles/${role.id}`, {
            preserveScroll: true,
        });
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
                                onClick={submit}
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
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setPermissionView(
                                                    view.id as typeof permissionView,
                                                )
                                            }
                                            className={`h-9 gap-2 rounded-lg px-3 text-xs font-bold transition-all ${
                                                permissionView === view.id
                                                    ? 'bg-card text-foreground shadow-sm dark:bg-white/10'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            <view.icon className="h-3.5 w-3.5" />
                                            {view.label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Employee Access Scope Section */}
                    <Card className="border-border bg-card dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="p-5 pb-3 sm:p-6 sm:pb-4">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                        <Building2 className="h-5 w-5" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-base font-bold tracking-tight">
                                            Employee Access Scope
                                        </CardTitle>
                                        <CardDescription className="text-xs leading-relaxed">
                                            Controls which employees users with
                                            this role can access throughout
                                            OMS-HRM. Module permissions still
                                            determine which features and
                                            employee data they can use.
                                        </CardDescription>
                                    </div>
                                </div>
                                {isOwner ? (
                                    <Badge
                                        variant="secondary"
                                        className="self-start rounded-full px-3 py-1 text-xs font-medium"
                                    >
                                        Protected Owner Role
                                    </Badge>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 p-5 pt-0 sm:p-6 sm:pt-0">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-all',
                                        visibilityScope === 'all'
                                            ? 'border-primary/50 bg-primary/5 shadow-xs'
                                            : 'border-border/70 hover:border-border hover:bg-muted/30 dark:border-white/10 dark:hover:bg-white/5',
                                        isOwner && 'cursor-default',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="employee_visibility_scope"
                                        value="all"
                                        checked={visibilityScope === 'all'}
                                        disabled={isOwner}
                                        onChange={() =>
                                            setVisibilityScope('all')
                                        }
                                        className="mt-0.5 h-4 w-4 text-primary"
                                    />
                                    <div className="space-y-1">
                                        <span className="text-sm font-semibold">
                                            All departments
                                        </span>
                                        <p className="text-xs text-muted-foreground">
                                            Users with this role may access
                                            employees across all departments in
                                            the active company.
                                        </p>
                                    </div>
                                </label>

                                <label
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-all',
                                        visibilityScope ===
                                            'selected_departments'
                                            ? 'border-primary/50 bg-primary/5 shadow-xs'
                                            : 'border-border/70 hover:border-border hover:bg-muted/30 dark:border-white/10 dark:hover:bg-white/5',
                                        isOwner &&
                                            'cursor-not-allowed opacity-50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="employee_visibility_scope"
                                        value="selected_departments"
                                        checked={
                                            visibilityScope ===
                                            'selected_departments'
                                        }
                                        disabled={isOwner}
                                        onChange={() =>
                                            setVisibilityScope(
                                                'selected_departments',
                                            )
                                        }
                                        className="mt-0.5 h-4 w-4 text-primary"
                                    />
                                    <div className="space-y-1">
                                        <span className="text-sm font-semibold">
                                            Selected departments
                                        </span>
                                        <p className="text-xs text-muted-foreground">
                                            Users with this role may only access
                                            employees whose current department
                                            belongs to one of the selected
                                            departments or their descendants.
                                        </p>
                                    </div>
                                </label>
                            </div>

                            {form.errors.employee_visibility_scope ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.employee_visibility_scope}
                                </div>
                            ) : null}

                            {form.errors.department_ids ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.department_ids}
                                </div>
                            ) : null}

                            {visibilityScope === 'selected_departments' &&
                            !isOwner ? (
                                <div className="mt-4 space-y-3 rounded-xl border border-border/70 bg-muted/20 p-4 dark:border-white/10 dark:bg-white/[0.02]">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-xs font-semibold text-foreground">
                                                Department Hierarchy
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Selecting a parent department
                                                automatically includes its child
                                                departments.
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 text-xs font-medium"
                                                onClick={() =>
                                                    setSelectedDepartmentIds(
                                                        allDepartmentIds,
                                                    )
                                                }
                                            >
                                                Select All
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 text-xs font-medium"
                                                onClick={() =>
                                                    setSelectedDepartmentIds([])
                                                }
                                            >
                                                Clear
                                            </Button>
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                {selectedDepartmentIds.length}{' '}
                                                selected
                                            </Badge>
                                        </div>
                                    </div>

                                    {department_tree.length === 0 ? (
                                        <p className="py-4 text-center text-xs text-muted-foreground">
                                            No active departments available for
                                            this company.
                                        </p>
                                    ) : (
                                        <div className="max-h-72 space-y-1 overflow-y-auto pr-1">
                                            {department_tree.map((node) => (
                                                <DepartmentTreeNodeRow
                                                    key={node.id}
                                                    node={node}
                                                    depth={0}
                                                    selectedIds={
                                                        selectedDeptSet
                                                    }
                                                    onToggle={toggleDepartment}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    {/* Main Workspace */}
                    <div className="grid min-h-[600px] grid-cols-1 gap-6 lg:grid-cols-12">
                        {/* Sidebar Navigator */}
                        <aside className="space-y-4 lg:sticky lg:top-6 lg:col-span-3 lg:self-start">
                            <Card className="flex max-h-96 flex-col gap-0 overflow-hidden border-border bg-card py-0 lg:h-[calc(100vh-6rem)] lg:max-h-[calc(100vh-6rem)] dark:border-white/5 dark:bg-white/5">
                                <div className="flex shrink-0 items-center justify-between border-b border-border bg-muted/20 p-4 dark:border-white/5 dark:bg-white/[0.02]">
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
                                <ScrollArea className="min-h-0 flex-1">
                                    <div className="space-y-1 p-2">
                                        {grouped.map(([group, subGroups]) => {
                                            const allItems = subGroups.flatMap(
                                                ([, items]) =>
                                                    items.map(
                                                        (item) => item.name,
                                                    ),
                                            );
                                            const selectedCount =
                                                allItems.filter((name) =>
                                                    selectedSet.has(name),
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
                                <div className="shrink-0 border-t border-border bg-muted/20 p-3 dark:border-white/5 dark:bg-white/[0.02]">
                                    <Button
                                        variant="ghost"
                                        className="h-8 w-full text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase hover:text-primary"
                                        onClick={() =>
                                            setSelectedPermissions(
                                                availablePermissionNames,
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
                                                ([, items]) =>
                                                    items.map(
                                                        (item) => item.name,
                                                    ),
                                            );
                                            const selectedCount =
                                                allItems.filter((name) =>
                                                    selectedSet.has(name),
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
                                                                                permission,
                                                                            ) =>
                                                                                selectedSet.has(
                                                                                    permission.name,
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
                                                                                                    items.map(
                                                                                                        (
                                                                                                            permission,
                                                                                                        ) =>
                                                                                                            permission.name,
                                                                                                    ),
                                                                                                );
                                                                                            setSelectedPermissions(
                                                                                                (
                                                                                                    prev,
                                                                                                ) =>
                                                                                                    prev.filter(
                                                                                                        (
                                                                                                            name,
                                                                                                        ) =>
                                                                                                            !remove.has(
                                                                                                                name,
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
                                                                                                        ...items.map(
                                                                                                            (
                                                                                                                permission,
                                                                                                            ) =>
                                                                                                                permission.name,
                                                                                                        ),
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

                                                                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                                                                {items.map(
                                                                                    (
                                                                                        permission,
                                                                                    ) => {
                                                                                        const checked =
                                                                                            selectedSet.has(
                                                                                                permission.name,
                                                                                            );

                                                                                        return (
                                                                                            <label
                                                                                                key={
                                                                                                    permission.name
                                                                                                }
                                                                                                className={`flex cursor-pointer items-start gap-4 rounded-2xl border p-4 transition-all ${
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
                                                                                                            permission.name,
                                                                                                            Boolean(
                                                                                                                value,
                                                                                                            ),
                                                                                                        )
                                                                                                    }
                                                                                                    className="mt-0.5 h-5 w-5 border-border data-[state=checked]:border-primary data-[state=checked]:bg-primary dark:border-white/10"
                                                                                                />
                                                                                                <div className="min-w-0 flex-1 space-y-1">
                                                                                                    <p
                                                                                                        className={`text-sm font-bold tracking-tight ${checked ? 'text-primary' : 'text-foreground/80'}`}
                                                                                                    >
                                                                                                        {
                                                                                                            permission.label
                                                                                                        }
                                                                                                    </p>
                                                                                                    {permission.description ? (
                                                                                                        <p className="text-xs leading-relaxed text-muted-foreground">
                                                                                                            {
                                                                                                                permission.description
                                                                                                            }
                                                                                                        </p>
                                                                                                    ) : null}
                                                                                                    <p className="font-mono text-[10px] text-muted-foreground/50">
                                                                                                        {
                                                                                                            permission.name
                                                                                                        }
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
