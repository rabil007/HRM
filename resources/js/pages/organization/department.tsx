import { Head, useForm } from '@inertiajs/react';
import { Activity, Briefcase, Crown, GitBranch, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DepartmentFormSheet } from '@/features/organization/departments/components/department-form-sheet';
import type {
    Branch,
    Company,
    Department as SheetDepartment,
    DepartmentFormData,
    DepartmentParentOption,
    Manager,
} from '@/features/organization/departments/types';
import { formatDisplayDate, formatDisplayValue } from '@/lib/format-date';
import { cn } from '@/lib/utils';

type Department = {
    id: number;
    company: { id: number; name: string | null; slug: string | null };
    branch: { id: number; name: string | null } | null;
    parent: { id: number; name: string | null } | null;
    manager: { id: number; name: string | null } | null;
    name: string;
    code: string | null;
    status: 'active' | 'inactive';
    positions_count: number;
    users_count: number;
    branches_count: number;
    created_at: string;
    updated_at: string;
};

type ChildDepartment = {
    id: number;
    name: string;
    code: string | null;
    positions_count: number;
    users_count: number;
};

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
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (m) => m.toUpperCase());
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

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">{label}</div>
            <div className="text-sm font-medium text-right">{value}</div>
        </div>
    );
}

export default function DepartmentDetails({
    department,
    child_departments,
    companies,
    branches,
    parents,
    managers,
    recent_activity,
    can_view_audit,
}: {
    department: Department;
    child_departments: ChildDepartment[];
    companies: Company[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
    recent_activity: ActivityItem[];
    can_view_audit: boolean;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    const form = useForm<DepartmentFormData>({
        company_id: department.company.id ?? '',
        branch_id: department.branch?.id ?? '',
        parent_id: department.parent?.id ?? '',
        manager_id: department.manager?.id ?? '',
        name: department.name ?? '',
        code: department.code ?? '',
        status: department.status ?? 'active',
    });

    const sheetDepartment: SheetDepartment = useMemo(() => {
        return {
            id: department.id,
            company: { id: department.company.id ?? 0, name: department.company.name ?? null },
            branch: department.branch ? { id: department.branch.id, name: department.branch.name ?? null } : null,
            parent: department.parent ? { id: department.parent.id, name: department.parent.name ?? null } : null,
            manager: department.manager ? { id: department.manager.id, name: department.manager.name ?? null } : null,
            name: department.name,
            code: department.code,
            status: department.status,
        };
    }, [department]);

    const submit = () => {
        form.put(`/organization/departments/${department.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <Main>
            <Head title={department.name} />

            <DetailsHeader
                title={department.name}
                description="Department details and settings."
                backHref="/organization/departments"
                backLabel="Back to departments"
                actions={
                    <Button
                        variant="outline"
                        className="rounded-xl border-input bg-background/50 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10 h-12 px-6"
                        onClick={() => setEditOpen(true)}
                    >
                        Edit
                    </Button>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="glass-card lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="text-xl font-bold tracking-tight">Overview</CardTitle>
                            <div className="text-sm text-muted-foreground/80">Key department information.</div>
                        </div>
                        <Badge
                            className={
                                department.status === 'active'
                                    ? 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-200'
                                    : 'bg-muted/60 text-muted-foreground border border-border/80 dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20'
                            }
                        >
                            {department.status}
                        </Badge>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field label="Company" value={department.company.name ?? '—'} />
                            <Field label="Branch" value={department.branch?.name ?? '—'} />
                            <Field label="Parent" value={department.parent?.name ?? '—'} />
                            {!department.parent ? (
                                <Field label="Manager" value={department.manager?.name ?? '—'} />
                            ) : null}
                            <Field label="Code" value={department.code ?? '—'} />
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">Quick info</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 dark:border-white/5 dark:bg-white/5 p-4">
                            <Briefcase className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Positions
                                </div>
                                <div className="text-sm font-semibold truncate">{department.positions_count}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 dark:border-white/5 dark:bg-white/5 p-4">
                            <Users className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Users
                                </div>
                                <div className="text-sm font-semibold truncate">{department.users_count}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 dark:border-white/5 dark:bg-white/5 p-4">
                            <GitBranch className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Branch
                                </div>
                                <div className="text-sm font-semibold truncate">{department.branches_count ?? '—'}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-border/80 bg-muted/30 dark:border-white/5 dark:bg-white/5 p-4">
                            <Crown className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Status
                                </div>
                                <div className="text-sm font-semibold truncate">{department.status}</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {child_departments && child_departments.length > 0 && (
                <Card className="glass-card mt-8 dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-center justify-between pb-4 border-b border-border dark:border-white/5">
                        <div className="flex items-center gap-3">
                            <div className="h-9 w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                                <GitBranch className="h-4 w-4" />
                            </div>
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Sub-departments
                                </CardTitle>
                                <div className="text-[10px] text-muted-foreground/50">
                                    Departments under this department.
                                </div>
                            </div>
                        </div>
                        <Badge className="bg-muted/50 text-muted-foreground border-border font-mono text-xs dark:bg-white/5 dark:border-white/10">
                            {child_departments.length}
                        </Badge>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            {child_departments.map((child) => (
                                <div key={child.id} className="flex items-center justify-between px-6 py-4 hover:bg-muted/30 transition-colors dark:hover:bg-white/[0.015]">
                                    <div className="space-y-1">
                                        <div className="text-sm font-semibold">{child.name}</div>
                                        {child.code && <div className="text-xs text-muted-foreground">{child.code}</div>}
                                    </div>
                                    <div className="flex items-center gap-6 text-sm">
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Briefcase className="h-4 w-4" />
                                            <span>{child.positions_count} positions</span>
                                        </div>
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            <Users className="h-4 w-4" />
                                            <span>{child.users_count} employees</span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {can_view_audit ? (
            <Card className="glass-card mt-8 dark:border-white/5 dark:bg-white/5">
                <CardHeader className="flex flex-row items-center justify-between pb-4 border-b border-border dark:border-white/5">
                    <div className="flex items-center gap-3">
                        <div className="h-9 w-9 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary">
                            <Activity className="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle className="text-base font-bold tracking-tight">
                                Recent activity
                            </CardTitle>
                            <div className="text-[10px] text-muted-foreground/50">
                                Latest changes for this department.
                            </div>
                        </div>
                    </div>
                    <Badge className="bg-muted/50 text-muted-foreground border-border font-mono text-xs dark:bg-white/5 dark:border-white/10">
                        {recent_activity.length}
                    </Badge>
                </CardHeader>
                <CardContent className="p-0">
                    {recent_activity.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center">
                            <div className="w-12 h-12 rounded-2xl bg-muted/30 border border-dashed border-border flex items-center justify-center mb-3 dark:bg-white/[0.03] dark:border-white/10">
                                <Activity className="w-5 h-5 text-muted-foreground/20" />
                            </div>
                            <p className="text-sm text-muted-foreground/50">
                                No activity recorded yet.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-border dark:divide-white/5">
                            {recent_activity.map((a) => {
                                const keys = changedKeys(a.old_values, a.new_values);
                                const isExpanded = expandedActivity[a.id] ?? false;
                                const shown = isExpanded ? keys : keys.slice(0, 4);
                                const showDescription =
                                    a.description.trim().toLowerCase() !== (a.event ?? '').trim().toLowerCase();

                                return (
                                    <div key={a.id} className="px-6 py-4 hover:bg-muted/30 transition-colors dark:hover:bg-white/[0.015]">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0 space-y-2 flex-1">
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

                                                {showDescription ? (
                                                    <p className="text-xs text-muted-foreground/70">
                                                        {a.description}
                                                    </p>
                                                ) : null}

                                                {shown.length > 0 ? (
                                                    <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                        {shown.map((k) => (
                                                            <span
                                                                key={k}
                                                                className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5"
                                                            >
                                                                {titleCaseKey(k)}:{' '}
                                                                <span className="text-muted-foreground/70">
                                                                    {formatDisplayValue(a.old_values?.[k])}
                                                                </span>{' '}
                                                                →{' '}
                                                                <span className="text-foreground/90">
                                                                    {formatDisplayValue(a.new_values?.[k])}
                                                                </span>
                                                            </span>
                                                        ))}
                                                        {keys.length > 4 ? (
                                                            <button
                                                                type="button"
                                                                className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground hover:bg-accent transition dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
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

                                            <div className="shrink-0 text-xs text-muted-foreground/50">
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
            ) : null}

            <DepartmentFormSheet
                open={editOpen}
                onOpenChange={setEditOpen}
                department={sheetDepartment}
                companies={companies}
                branches={branches}
                parents={parents}
                managers={managers}
                form={form}
                onSubmit={submit}
            />
        </Main>
    );
}

