import { Head, useForm } from '@inertiajs/react';
import { Activity, Building2, Mail, MapPin, Phone, Store } from 'lucide-react';
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
    recent_activity,
}: {
    branch: Branch;
    companies: Company[];
    countries: Country[];
    recent_activity: ActivityItem[];
}) {
    const location = [branch.city, branch.country].filter(Boolean).join(', ') || '—';
    const [editOpen, setEditOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<Record<number, boolean>>({});

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
                                    Latest changes for this branch.
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

