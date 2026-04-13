import { Head, useForm } from '@inertiajs/react';
import { Building2, Crown, Layers, User } from 'lucide-react';
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
    created_at: string;
    updated_at: string;
};

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
}: {
    department: Department;
    companies: Company[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    const [editOpen, setEditOpen] = useState(false);

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
                            <Building2 className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Company
                                </div>
                                <div className="text-sm font-semibold truncate">{department.company.name ?? '—'}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
                            <Layers className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Parent
                                </div>
                                <div className="text-sm font-semibold truncate">{department.parent?.name ?? '—'}</div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3 rounded-xl border border-white/5 bg-white/5 p-4">
                            <User className="h-5 w-5 text-primary" />
                            <div className="min-w-0">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Manager
                                </div>
                                <div className="text-sm font-semibold truncate">{department.manager?.name ?? '—'}</div>
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

