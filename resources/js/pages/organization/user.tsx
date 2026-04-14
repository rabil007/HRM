import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { UserFormSheet } from '@/features/organization/users/components/user-form-sheet';
import type { User, UserFormData } from '@/features/organization/users/types';

export default function UserDetails({
    user,
    roles,
}: {
    user: User & { updated_at?: string };
    roles: { id: number; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<UserFormData>({
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
        avatar: null,
        role_id: user.role?.id ?? '',
        status: user.status ?? 'active',
    });

    const statusClass =
        user.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : user.status === 'suspended'
              ? 'bg-amber-500/10 text-amber-200 border-amber-500/20'
              : 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20';

    return (
        <>
            <Head title={`User • ${user.name}`} />
            <Main>
                <DetailsHeader
                    kicker="Organization"
                    title={user.name}
                    description={user.email}
                    backHref="/organization/users"
                    backLabel="Back to users"
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
                                <div className="text-sm font-semibold text-muted-foreground/80">Avatar</div>
                                <div className="h-12 w-12 rounded-2xl bg-white/6 border border-white/10 overflow-hidden flex items-center justify-center text-foreground/80">
                                    {user.avatar ? (
                                        <img src={user.avatar} alt={user.name} className="h-full w-full object-cover" loading="lazy" />
                                    ) : (
                                        <div className="text-xs font-semibold text-muted-foreground/80">—</div>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Status</div>
                                <Badge className={`text-[10px] uppercase font-bold tracking-wider border ${statusClass}`}>{user.status}</Badge>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Company</div>
                                <div className="text-sm font-bold">{user.company?.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Role</div>
                                <div className="text-sm font-bold">{user.role?.name ?? '—'}</div>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-semibold text-muted-foreground/80">Last login</div>
                                <div className="text-sm font-bold">{user.last_login_at ?? '—'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <UserFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    user={user}
                    roles={roles}
                    form={form}
                    onSubmit={() => {
                        form.put(`/organization/users/${user.id}`, {
                            preserveScroll: true,
                            forceFormData: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}

