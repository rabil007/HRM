import { Head } from '@inertiajs/react';
import { Building2, Mail, MapPin, Phone, Store } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

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

export default function BranchDetails({ branch }: { branch: Branch }) {
    const location = [branch.city, branch.country].filter(Boolean).join(', ') || '—';

    return (
        <>
            <Head title={`Branch • ${branch.name}`} />
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
                            {branch.name}
                        </h1>
                        <p className="text-sm text-muted-foreground/80 font-medium">
                            Full branch details and contact information.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            variant="outline"
                            className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-12 px-6"
                            asChild
                        >
                            <a href="/organization/branches">Back to branches</a>
                        </Button>
                        {branch.company.id ? (
                            <Button className="rounded-xl shadow-lg shadow-primary/20 h-12 px-6" asChild>
                                <a href={`/organization/companies/${branch.company.id}`}>
                                    <Building2 className="mr-2 h-4 w-4" />
                                    Company
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
            </Main>
        </>
    );
}

