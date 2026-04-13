import { Head, useForm } from '@inertiajs/react';
import { Building2, Mail, MapPin, Phone, Store } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BranchFormSheet } from '@/features/organization/branches/components/branch-form-sheet';
import type { Branch as SheetBranch, BranchFormData, Company, Country } from '@/features/organization/branches/types';

type Branch = {
    id: number;
    company: { id: number; name: string | null; slug: string | null };
    name: string;
    code: string | null;
    address: string | null;
    city: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    is_headquarters: boolean;
    status: 'active' | 'inactive';
    created_at: string;
    updated_at: string;
};

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                {label}
            </div>
            <div className="text-sm font-medium">{value}</div>
        </div>
    );
}

export default function BranchDetails({
    branch,
    companies,
    countries,
}: {
    branch: Branch;
    companies: Company[];
    countries: Country[];
}) {
    const location = [branch.city, branch.country].filter(Boolean).join(', ') || '—';
    const [editOpen, setEditOpen] = useState(false);

    const form = useForm<BranchFormData>({
        company_id: branch.company.id ?? '',
        name: branch.name ?? '',
        code: branch.code ?? '',
        address: branch.address ?? '',
        city: branch.city ?? '',
        country: branch.country ?? '',
        phone: branch.phone ?? '',
        email: branch.email ?? '',
        is_headquarters: branch.is_headquarters ?? false,
        status: branch.status ?? 'active',
    });

    const sheetBranch: SheetBranch = {
        id: branch.id,
        company: { id: branch.company.id ?? 0, name: branch.company.name ?? null },
        name: branch.name,
        code: branch.code,
        address: branch.address,
        city: branch.city,
        country: branch.country,
        phone: branch.phone,
        email: branch.email,
        is_headquarters: branch.is_headquarters,
        status: branch.status,
    };

    const submit = () => {
        form.put(`/organization/branches/${branch.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <>
            <Head title={`Branch • ${branch.name}`} />
            <Main>
                <DetailsHeader
                    title={branch.name}
                    description="Full branch details and contact information."
                    backHref="/organization/branches"
                    backLabel="Back to branches"
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                                onClick={() => setEditOpen(true)}
                            >
                                Edit
                            </Button>
                            {branch.company.id ? (
                                <Button className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6" asChild>
                                    <a href={`/organization/companies/${branch.company.id}`}>
                                        <Building2 className="mr-2 h-4 w-4" />
                                        Company
                                    </a>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl lg:col-span-2 overflow-hidden">
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-4">
                                <div className="h-14 w-14 rounded-2xl bg-primary/10 flex items-center justify-center border border-primary/20 text-primary">
                                    <Store className="h-7 w-7" />
                                </div>
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                            {branch.status}
                                        </Badge>
                                        {branch.is_headquarters ? (
                                            <Badge className="bg-primary/15 text-primary border-primary/20 text-[10px] uppercase font-bold tracking-wider">
                                                Headquarters
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <div className="mt-2 flex items-center gap-2 text-sm text-muted-foreground/80">
                                        <Building2 className="h-4 w-4" />
                                        {branch.company.name ?? '—'}
                                        <span className="mx-1">•</span>
                                        <MapPin className="h-4 w-4" />
                                        {location}
                                    </div>
                                </div>
                            </div>
                        </CardHeader>

                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <Field label="Code" value={branch.code ?? '—'} />
                            <Field label="Address" value={branch.address ?? '—'} />
                            <Field label="City" value={branch.city ?? '—'} />
                            <Field label="Country" value={branch.country ?? '—'} />
                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Contact
                                </div>
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Phone className="h-4 w-4 text-muted-foreground/80" />
                                        {branch.phone ?? '—'}
                                    </div>
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Mail className="h-4 w-4 text-muted-foreground/80" />
                                        {branch.email ?? '—'}
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Metadata
                                </div>
                                <div className="space-y-2 text-sm font-medium">
                                    <div>Created: {branch.created_at}</div>
                                    <div>Updated: {branch.updated_at}</div>
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
                                <a href={`/organization/branches`}>Edit from list</a>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <BranchFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    branch={sheetBranch}
                    companies={companies}
                    countries={countries}
                    form={form}
                    onSubmit={submit}
                />
            </Main>
        </>
    );
}

