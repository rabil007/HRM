import { Head } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PositionFormSheet } from '@/features/organization/positions/components/position-form-sheet';
import type { Company, DepartmentOption, Position, PositionFormData } from '@/features/organization/positions/types';

export default function PositionDetails({
    position,
    companies,
    departments,
}: {
    position: Position & { updated_at?: string };
    companies: Company[];
    departments: DepartmentOption[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<PositionFormData>({
        company_id: position.company.id ?? '',
        department_id: position.department?.id ?? '',
        title: position.title ?? '',
        grade: position.grade ?? '',
        min_salary: position.min_salary ? String(position.min_salary) : '',
        max_salary: position.max_salary ? String(position.max_salary) : '',
        status: position.status ?? 'active',
    });

    const statusClass =
        position.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20';

    return (
        <>
            <Head title={`Position • ${position.title}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={position.title}
                    description={`${position.company.name ?? '—'}${position.department?.name ? ` • ${position.department.name}` : ''}`}
                    backHref="/organization/positions"
                    backLabel="Back to positions"
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
                                <div className="text-sm font-semibold text-muted-foreground/80">Status</div>
                                <Badge className={`text-[10px] uppercase font-bold tracking-wider border ${statusClass}`}>
                                    {position.status}
                                </Badge>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Company</div>
                                <div className="text-sm font-bold">{position.company.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Department</div>
                                <div className="text-sm font-bold">{position.department?.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Grade</div>
                                <div className="text-sm font-bold">{position.grade ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Min salary</div>
                                <div className="text-sm font-bold">{position.min_salary ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Max salary</div>
                                <div className="text-sm font-bold">{position.max_salary ?? '—'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <PositionFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    position={position}
                    companies={companies}
                    departments={departments}
                    form={form}
                    onSubmit={() => {
                        form.put(`/organization/positions/${position.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}

