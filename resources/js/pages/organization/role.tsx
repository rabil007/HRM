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
    company,
    permissions,
}: {
    role: Role & { updated_at?: string };
    company: (Company & { slug?: string }) | null;
    permissions: { id: number; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<RoleFormData>({
        name: role.name ?? '',
        permissions: role.permissions ?? [],
    });

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
                        <Button className="rounded-xl h-11 px-5" onClick={() => setOpen(true)}>
                            Edit
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-4">
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
                    permissions={permissions}
                    form={form}
                    onSubmit={() => {
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

