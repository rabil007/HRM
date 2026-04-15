import { Head, useForm } from '@inertiajs/react';
import { Activity, Briefcase, Building2, CalendarDays, Mail, MapPin, Phone, User2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmployeeFormSheet } from '@/features/organization/employees/components/employee-form-sheet';
import type {
    BranchOption,
    DepartmentOption,
    EmployeeFormData,
    ManagerOption,
    PositionOption,
    UserOption,
} from '@/features/organization/employees/types';

type EmployeeDetails = {
    id: number;
    user: { id: number; name: string | null; email: string | null } | null;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    manager: { id: number; employee_no: string | null; name: string | null } | null;
    employee_no: string;
    first_name: string;
    last_name: string;
    work_email: string | null;
    phone: string | null;
    hire_date: string;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
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
    return key.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
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
    const keys = new Set<string>([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})]);

    return [...keys].filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k)).sort((a, b) => a.localeCompare(b));
}

function statusBadgeClass(status: EmployeeDetails['status']): string {
    if (status === 'active') {
        return 'bg-emerald-500/10 text-emerald-200 border border-emerald-500/20';
    }

    if (status === 'inactive') {
        return 'bg-zinc-500/10 text-zinc-200 border border-zinc-500/20';
    }

    if (status === 'on_leave') {
        return 'bg-amber-500/10 text-amber-200 border border-amber-500/20';
    }

    return 'bg-rose-500/10 text-rose-200 border border-rose-500/20';
}

export default function EmployeeDetails({
    employee,
    branches,
    departments,
    positions,
    managers,
    users,
    recent_activity,
}: {
    employee: EmployeeDetails;
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    recent_activity: ActivityItem[];
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

    const form = useForm<EmployeeFormData>({
        user_id: employee.user?.id ?? '',
        branch_id: employee.branch?.id ?? '',
        department_id: employee.department?.id ?? '',
        position_id: employee.position?.id ?? '',
        manager_id: employee.manager?.id ?? '',
        employee_no: employee.employee_no ?? '',
        first_name: employee.first_name ?? '',
        last_name: employee.last_name ?? '',
        work_email: employee.work_email ?? '',
        phone: employee.phone ?? '',
        hire_date: employee.hire_date ?? '',
        contract_type: employee.contract_type ?? 'unlimited',
        status: employee.status ?? 'active',
    });

    const displayName = useMemo(() => {
        return `${employee.first_name ?? ''} ${employee.last_name ?? ''}`.trim() || 'Employee';
    }, [employee.first_name, employee.last_name]);

    const submit = () => {
        form.put(`/organization/employees/${employee.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <>
            <Head title={`Employee • ${displayName}`} />
            <Main>
                <DetailsHeader
                    title={displayName}
                    description="Employee profile and assignment details."
                    backHref="/organization/employees"
                    backLabel="Back to employees"
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
                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl lg:col-span-2 overflow-hidden">
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-4">
                                <div className="h-14 w-14 rounded-2xl bg-primary/10 flex items-center justify-center border border-primary/20 text-primary">
                                    <User2 className="h-7 w-7" />
                                </div>
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className={statusBadgeClass(employee.status)}>{employee.status}</Badge>
                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                            {employee.employee_no}
                                        </Badge>
                                    </div>
                                    <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted-foreground/80">
                                        <div className="flex items-center gap-2">
                                            <Building2 className="h-4 w-4" />
                                            {employee.branch?.name ?? '—'}
                                        </div>
                                        <span className="text-muted-foreground/50">•</span>
                                        <div className="flex items-center gap-2">
                                            <Briefcase className="h-4 w-4" />
                                            {employee.position?.title ?? '—'}
                                        </div>
                                        <span className="text-muted-foreground/50">•</span>
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4" />
                                            {employee.department?.name ?? '—'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Contact
                                </div>
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Mail className="h-4 w-4 text-muted-foreground/80" />
                                        {employee.work_email ?? '—'}
                                    </div>
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Phone className="h-4 w-4 text-muted-foreground/80" />
                                        {employee.phone ?? '—'}
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Employment
                                </div>
                                <div className="space-y-2 text-sm font-medium">
                                    <div className="flex items-center gap-2">
                                        <CalendarDays className="h-4 w-4 text-muted-foreground/80" />
                                        Hire date: {employee.hire_date ?? '—'}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Briefcase className="h-4 w-4 text-muted-foreground/80" />
                                        Contract: {employee.contract_type ?? '—'}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-lg font-bold tracking-tight">Quick actions</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Button
                                variant="outline"
                                className="w-full rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12"
                                asChild
                            >
                                <a href="/organization/employees">Edit from list</a>
                            </Button>
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
                                <CardTitle className="text-lg font-bold tracking-tight">Recent activity</CardTitle>
                                <div className="text-xs text-muted-foreground/70">Latest changes for this employee.</div>
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
                                                        <div className="text-sm font-medium">{a.causer?.name ?? 'System'}</div>
                                                        <div className="text-xs text-muted-foreground/70">
                                                            {a.causer?.email ? `(${a.causer.email})` : ''}
                                                        </div>
                                                    </div>

                                                    {showDescription ? (
                                                        <div className="text-sm text-muted-foreground/90">{a.description}</div>
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

                <EmployeeFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    employee={{
                        id: employee.id,
                        user_id: employee.user?.id ?? null,
                        branch_id: employee.branch?.id ?? null,
                        department_id: employee.department?.id ?? null,
                        position_id: employee.position?.id ?? null,
                        manager_id: employee.manager?.id ?? null,
                        employee_no: employee.employee_no,
                        first_name: employee.first_name,
                        last_name: employee.last_name,
                        name: displayName,
                        branch: employee.branch,
                        department: employee.department,
                        position: employee.position,
                        work_email: employee.work_email,
                        phone: employee.phone,
                        status: employee.status,
                        hire_date: employee.hire_date,
                        contract_type: employee.contract_type,
                        created_at: employee.created_at,
                    }}
                    form={form}
                    onSubmit={submit}
                    branches={branches}
                    departments={departments}
                    positions={positions}
                    managers={managers}
                    users={users}
                />
            </Main>
        </>
    );
}

