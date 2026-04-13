import { Head, useForm } from '@inertiajs/react';
import { Building2, ExternalLink, Globe, Mail, MapPin, Phone, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CompanyFormSheet } from '@/features/organization/companies/components/company-form-sheet';
import type { Company as SheetCompany, CompanyFormData, Country, Currency } from '@/features/organization/companies/types';

type Company = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    industry: string | null;
    company_size: string | null;
    registration_number: string | null;
    tax_id: string | null;
    country: { id: number; code: string | null; name: string | null; dial_code: string | null };
    city: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    currency: { id: number; code: string | null; name: string | null; symbol: string | null };
    timezone: string | null;
    payroll_cycle: string | null;
    working_days: number[] | null;
    wps_agent_code: string | null;
    wps_mol_uid: string | null;
    status: string | null;
    created_at: string;
    updated_at: string;
};

type Branch = {
    id: number;
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

export default function CompanyDetails({
    company,
    branches,
    countries,
    currencies,
}: {
    company: Company;
    branches: Branch[];
    countries: Country[];
    currencies: Currency[];
}) {
    const [editOpen, setEditOpen] = useState(false);

    const form = useForm<CompanyFormData>({
        logo: null as File | null,
        name: company.name ?? '',
        industry: company.industry ?? '',
        company_size: company.company_size ?? '',
        registration_number: company.registration_number ?? '',
        tax_id: company.tax_id ?? '',
        city: company.city ?? '',
        address: company.address ?? '',
        phone: company.phone ?? '',
        country_id: company.country.id ?? '',
        email: company.email ?? '',
        website: company.website ?? '',
        currency_id: company.currency.id ?? '',
        timezone: company.timezone ?? 'Asia/Dubai',
        payroll_cycle: (company.payroll_cycle as 'monthly' | 'biweekly' | 'weekly') ?? 'monthly',
        working_days: company.working_days ?? [1, 2, 3, 4, 5],
        wps_agent_code: company.wps_agent_code ?? '',
        wps_mol_uid: company.wps_mol_uid ?? '',
        status: (company.status as 'active' | 'suspended' | 'inactive') ?? 'active',
    });

    const location = [company.city, company.country.code].filter(Boolean).join(', ') || '—';
    const website = company.website
        ? company.website.startsWith('http')
            ? company.website
            : `https://${company.website}`
        : null;

    const sheetCompany: SheetCompany = {
        id: company.id,
        name: company.name,
        slug: company.slug,
        logo_url: company.logo_url,
        industry: company.industry,
        city: company.city,
        country: { id: company.country.id, code: company.country.code, name: company.country.name },
        company_size: company.company_size,
        registration_number: company.registration_number,
        tax_id: company.tax_id,
        address: company.address,
        phone: company.phone,
        email: company.email,
        website: company.website,
        currency: { id: company.currency.id, code: company.currency.code },
        timezone: company.timezone,
        payroll_cycle: company.payroll_cycle,
        working_days: company.working_days,
        wps_agent_code: company.wps_agent_code,
        wps_mol_uid: company.wps_mol_uid,
        status: company.status as 'active' | 'suspended' | 'inactive' | null,
    };

    const submit = () => {
        form.put(`/organization/companies/${company.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    return (
        <>
            <Head title={`Company • ${company.name}`} />
            <Main>
                <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                    <div className="space-y-1.5">
                        <div className="flex items-center gap-2 mb-1">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                Organization Management
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                            {company.name}
                        </h1>
                        <p className="text-sm text-muted-foreground/80 font-medium">
                            Full company profile and configuration.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            variant="outline"
                            className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                            asChild
                        >
                            <a href="/organization/companies">Back to companies</a>
                        </Button>
                        <Button
                            variant="outline"
                            className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </Button>
                        {website ? (
                            <Button className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6" asChild>
                                <a href={website} target="_blank" rel="noreferrer noopener">
                                    <ExternalLink className="mr-2 h-4 w-4" />
                                    Website
                                </a>
                            </Button>
                        ) : null}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl lg:col-span-2 overflow-hidden">
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-4">
                                <div className="h-14 w-14 rounded-2xl bg-primary/10 flex items-center justify-center border border-primary/20 text-primary">
                                    {company.logo_url ? (
                                        <img
                                            src={company.logo_url}
                                            alt={company.name}
                                            className="h-14 w-14 rounded-2xl object-cover"
                                        />
                                    ) : (
                                        <Building2 className="h-7 w-7" />
                                    )}
                                </div>
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                            {company.status ?? '—'}
                                        </Badge>
                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                            {company.currency.code ?? '—'}
                                        </Badge>
                                    </div>
                                    <div className="mt-2 flex items-center gap-2 text-sm text-muted-foreground/80">
                                        <MapPin className="h-4 w-4" />
                                        {location}
                                        <span className="mx-1">•</span>
                                        {company.industry ?? '—'}
                                    </div>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <Field label="Slug" value={company.slug || '—'} />
                            <Field label="Company size" value={company.company_size ?? '—'} />
                            <Field label="Registration number" value={company.registration_number ?? '—'} />
                            <Field label="Tax ID" value={company.tax_id ?? '—'} />
                            <Field
                                label="Country"
                                value={
                                    `${company.country.code ?? ''} ${company.country.name ?? ''}`.trim() || '—'
                                }
                            />
                            <Field label="Address" value={company.address ?? '—'} />
                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Contact
                                </div>
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Phone className="h-4 w-4 text-muted-foreground/80" />
                                        {company.phone ?? '—'}
                                    </div>
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Mail className="h-4 w-4 text-muted-foreground/80" />
                                        {company.email ?? '—'}
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <div className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                    Locale & Currency
                                </div>
                                <div className="space-y-2 text-sm font-medium">
                                    <div className="flex items-center gap-2">
                                        <Globe className="h-4 w-4 text-muted-foreground/80" />
                                        {company.timezone ?? '—'}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <ShieldCheck className="h-4 w-4 text-muted-foreground/80" />
                                        {`${company.currency.code ?? ''} ${company.currency.name ?? ''}`.trim() || '—'}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-lg font-bold tracking-tight">Payroll & Compliance</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <Field label="Payroll cycle" value={company.payroll_cycle ?? '—'} />
                            <Field
                                label="Working days"
                                value={company.working_days?.length ? company.working_days.join(', ') : '—'}
                            />
                            <div className="h-px bg-white/5" />
                            <Field label="WPS agent code" value={company.wps_agent_code ?? '—'} />
                            <Field label="WPS MOL UID" value={company.wps_mol_uid ?? '—'} />
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-8">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-xl font-bold tracking-tight">Branches</h2>
                        <Badge className="bg-white/5 text-muted-foreground border-white/10">
                            {branches.length} total
                        </Badge>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {branches.map((branch) => (
                            <Card
                                key={branch.id}
                                className="border-white/5 bg-white/5 backdrop-blur-xl hover:bg-white/10 transition-all overflow-hidden"
                            >
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <CardTitle className="text-lg font-bold tracking-tight line-clamp-1">
                                                {branch.name}
                                            </CardTitle>
                                            <div className="mt-1 text-xs text-muted-foreground/80">
                                                {branch.code ? `Code: ${branch.code}` : branch.is_headquarters ? 'Headquarters' : '—'}
                                            </div>
                                        </div>
                                        <Badge className="bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider">
                                            {branch.status}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="text-sm text-muted-foreground/80 flex items-center gap-2">
                                        <MapPin className="h-4 w-4" />
                                        {[branch.city, branch.country].filter(Boolean).join(', ') || '—'}
                                    </div>
                                    <div className="flex gap-2 pt-2">
                                        <Button
                                            variant="outline"
                                            className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 flex-1"
                                            asChild
                                        >
                                            <a href={`/organization/branches/${branch.id}`}>View</a>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>

                    {branches.length === 0 ? (
                        <div className="rounded-xl border border-white/5 bg-white/5 backdrop-blur-xl p-10 text-sm text-muted-foreground/80 text-center">
                            No branches yet.
                        </div>
                    ) : null}
                </div>

                <CompanyFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    company={sheetCompany}
                    countries={countries}
                    currencies={currencies}
                    form={form}
                    onSubmit={submit}
                />
            </Main>
        </>
    );
}

