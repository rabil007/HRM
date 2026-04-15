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

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">{label}</div>
            <div className="text-sm font-medium">{value}</div>
        </div>
    );
}

export default function DepartmentDetails({
    department,
    companies,
    branches,
    parents,
    managers,
    recent_activity,
}: {
    department: Department;
    companies: Company[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
    recent_activity: ActivityItem[];
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
                        className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                        onClick={() => setEditOpen(true)}
                    >
                        Edit
                    </Button>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2 border-white/5 bg-white/5 backdrop-blur-xl">
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="text-xl font-bold tracking-tight">Overview</CardTitle>
                            <div className="text-sm text-muted-foreground/80">Key department information.</div>
                        </div>
                        <Badge
                            className={
                                department.status === 'active'
                                    ? 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/20'
                                    : 'bg-zinc-500/10 text-zinc-200 border border-zinc-500/20'
                            }
                        >
                            {department.status}
                        </Badge>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Company" value={department.company.name ?? '—'} />
                            <Field label="Branch" value={department.branch?.name ?? '—'} />
                            <Field label="Parent" value={department.parent?.name ?? '—'} />
                            <Field label="Manager" value={department.manager?.name ?? '—'} />
                            <Field label="Code" value={department.code ?? '—'} />
                        </div>
                    </CardContent>
                </Card>

                <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">Quick info</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
                            <Briefcase className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Positions
                                </div>
                                <div className="text-sm font-semibold truncate">{department.positions_count}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
                            <Users className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Users
                                </div>
                                <div className="text-sm font-semibold truncate">{department.users_count}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
                            <GitBranch className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Branch
                                </div>
                                <div className="text-sm font-semibold truncate">{department.branches_count ?? '—'}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
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

            <Card className="border-white/5 bg-white/5 backdrop-blur-xl mt-8">
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
                                Latest changes for this department.
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

