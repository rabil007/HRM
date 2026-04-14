import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { RoleFormSheet } from '@/features/organization/roles/components/role-form-sheet';
import type { Company, Role, RoleFormData } from '@/features/organization/roles/types';

export default function RoleDetails({
    role,
    companies,
}: {
    role: Role & { updated_at?: string };
    companies: Company[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<RoleFormData>({
        company_id: role.company.id ?? '',
        name: role.name ?? '',
        slug: role.slug ?? '',
        permissions: role.permissions ?? [],
        is_system: Boolean(role.is_system),
    });

    return (
        <>
            <Head title={`Role • ${role.name}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={role.name}
                    description={`${role.company.name ?? '—'} • ${role.slug}`}
                    backHref="/organization/roles"
                    backLabel="Back to roles"
                    actions={
                        <Button className="rounded-xl h-11 px-5" onClick={() => setOpen(true)} disabled={role.is_system}>
                            Edit
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-4">
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">System</div>
                                <Badge className="text-[10px] uppercase font-bold tracking-wider border bg-white/5 text-muted-foreground border-white/10">
                                    {role.is_system ? 'Yes' : 'No'}
                                </Badge>
                            </div>
                            <div className="space-y-2">
                                <div className="text-sm font-semibold text-muted-foreground/80">Permissions</div>
                                <div className="flex flex-wrap gap-2">
                                    {(role.permissions ?? []).length ? (
                                        role.permissions.map((p) => (
                                            <Badge key={p} variant="secondary" className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                                {p}
                                            </Badge>
                                        ))
                                    ) : (
                                        <div className="text-sm text-muted-foreground/80">No permissions.</div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <RoleFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    role={role}
                    companies={companies}
                    form={form}
                    onSubmit={() => {
                        if (role.is_system) {
                            return;
                        }

                        form.put(`/organization/roles/${role.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}

