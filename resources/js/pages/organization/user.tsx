import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { UserFormSheet } from '@/features/organization/users/components/user-form-sheet';
import type { Company, User, UserFormData } from '@/features/organization/users/types';

type Membership = {
    company: { id: number; name: string };
    status: 'active' | 'inactive';
    roles: string[];
};

export default function UserDetails({
    user,
    companies,
    memberships,
    available_companies,
    spatie_roles,
}: {
    user: User & { updated_at?: string };
    companies: Company[];
    memberships: Membership[];
    available_companies: Company[];
    spatie_roles: { id: number; company_id: number; name: string }[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<UserFormData>({
        company_id: user.company?.id ?? '',
        name: user.name ?? '',
        email: user.email ?? '',
        password: '',
        avatar: user.avatar ?? '',
        status: user.status ?? 'active',
    });

    const statusClass =
        user.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-200 border-emerald-500/20'
            : user.status === 'suspended'
              ? 'bg-amber-500/10 text-amber-200 border-amber-500/20'
              : 'bg-zinc-500/10 text-zinc-200 border-zinc-500/20';

    const [newCompanyId, setNewCompanyId] = useState<number | ''>('');
    const [newCompanyRoleId, setNewCompanyRoleId] = useState<number | ''>('');

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

                    <Card className="border-white/5 bg-white/5">
                        <CardContent className="p-6 space-y-5">
                            <div className="text-sm font-semibold text-muted-foreground/80">Memberships</div>

                            {memberships.length ? (
                                <div className="space-y-3">
                                    {memberships.map((m) => {
                                        const currentRoleName = m.roles?.[0] ?? '';
                                        const roleOptions = spatie_roles.filter((r) => r.company_id === m.company.id);
                                        const currentRoleId = roleOptions.find((r) => r.name === currentRoleName)?.id ?? '';

                                        return (
                                            <div key={m.company.id} className="rounded-xl border border-white/10 bg-white/5 p-4 space-y-3">
                                                <div className="flex items-center justify-between gap-3">
                                                    <div className="text-sm font-bold">{m.company.name}</div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        className="rounded-xl h-9 px-3 text-destructive hover:text-destructive hover:bg-destructive/10"
                                                        onClick={() => {
                                                            router.delete(`/organization/users/${user.id}/memberships/${m.company.id}`, { preserveScroll: true });
                                                        }}
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>

                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="space-y-2">
                                                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Status</Label>
                                                        <select
                                                            className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                                            defaultValue={m.status}
                                                            onChange={(e) => {
                                                                router.put(
                                                                    `/organization/users/${user.id}/memberships/${m.company.id}`,
                                                                    { status: e.target.value, role_id: currentRoleId },
                                                                    { preserveScroll: true },
                                                                );
                                                            }}
                                                        >
                                                            <option value="active">Active</option>
                                                            <option value="inactive">Inactive</option>
                                                        </select>
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Role</Label>
                                                        <select
                                                            className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                                            defaultValue={currentRoleId}
                                                            onChange={(e) => {
                                                                const role_id = e.target.value ? Number(e.target.value) : '';

                                                                router.put(
                                                                    `/organization/users/${user.id}/memberships/${m.company.id}`,
                                                                    { status: m.status, role_id },
                                                                    { preserveScroll: true },
                                                                );
                                                            }}
                                                        >
                                                            <option value="">No role</option>
                                                            {roleOptions.map((r) => (
                                                                <option key={r.id} value={r.id}>
                                                                    {r.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="text-sm text-muted-foreground/80">No memberships.</div>
                            )}

                            <div className="pt-2 border-t border-white/10">
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70 mb-3">Add membership</div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Company</Label>
                                        <select
                                            className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                            value={newCompanyId}
                                            onChange={(e) => {
                                                const id = e.target.value ? Number(e.target.value) : '';
                                                setNewCompanyId(id);
                                                setNewCompanyRoleId('');
                                            }}
                                        >
                                            <option value="">Select company</option>
                                            {available_companies.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">Role</Label>
                                        <select
                                            className="w-full rounded-xl border border-white/10 bg-white/5 h-11 px-3 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 transition-all"
                                            value={newCompanyRoleId}
                                            onChange={(e) => setNewCompanyRoleId(e.target.value ? Number(e.target.value) : '')}
                                            disabled={!newCompanyId}
                                        >
                                            <option value="">No role</option>
                                            {spatie_roles
                                                .filter((r) => r.company_id === newCompanyId)
                                                .map((r) => (
                                                    <option key={r.id} value={r.id}>
                                                        {r.name}
                                                    </option>
                                                ))}
                                        </select>
                                    </div>
                                </div>

                                <div className="mt-4 flex justify-end">
                                    <Button
                                        type="button"
                                        className="rounded-xl h-11 px-5"
                                        onClick={() => {
                                            if (!newCompanyId) {
                                                return;
                                            }

                                            router.post(
                                                `/organization/users/${user.id}/memberships`,
                                                { company_id: newCompanyId, role_id: newCompanyRoleId || null, status: 'active' },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        setNewCompanyId('');
                                                        setNewCompanyRoleId('');
                                                    },
                                                },
                                            );
                                        }}
                                        disabled={!newCompanyId}
                                    >
                                        Add
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <UserFormSheet
                    open={open}
                    onOpenChange={setOpen}
                    user={user}
                    companies={companies}
                    form={form}
                    onSubmit={() => {
                        form.put(`/organization/users/${user.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                />
            </Main>
        </>
    );
}

