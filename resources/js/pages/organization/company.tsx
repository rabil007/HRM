import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    Building2,
    CalendarClock,
    CalendarDays,
    ExternalLink,
    Fingerprint,
    Hash,
    Home,
    IdCard,
    ReceiptText,
    Users,
    Globe,
    Mail,
    MapPin,
    Phone,
    ShieldCheck,
    FolderOpen,
    Eye,
    Download,
    FileSignature,
    ImageIcon,
    PenLine,
} from 'lucide-react';
import { useState } from 'react';
import type { ComponentType } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CompanyDocumentSettingsSheet } from '@/features/organization/companies/components/company-document-settings-sheet';
import { CompanyFormSheet } from '@/features/organization/companies/components/company-form-sheet';
import type {
    Company as SheetCompany,
    CompanyDocumentSettings,
    CompanyFormData,
    Country,
    Currency,
} from '@/features/organization/companies/types';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { formatDisplayDate, formatDisplayValue, formatActivityFieldLabel } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { index as companyDocumentsIndex } from '@/routes/organization/companies/documents';

type CompanyDocumentSummaryItem = {
    id: number;
    title: string;
    original_filename: string;
    mime_type: string;
    expiry_date: string | null;
    expiry_status: string;
    can_preview: boolean;
    preview_url: string;
    download_url: string;
};

type Company = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    industry: string | null;
    company_size: string | null;
    registration_number: string | null;
    tax_id: string | null;
    country: {
        id: number;
        code: string | null;
        name: string | null;
        dial_code: string | null;
    };
    city: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    currency: {
        id: number;
        code: string | null;
        name: string | null;
        symbol: string | null;
    };
    timezone: string | null;
    payroll_cycle: string | null;
    working_days: number[] | null;
    wps_agent_code: string | null;
    wps_mol_uid: string | null;
    wps_employer_iban: string | null;
    status: string | null;
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


function eventColor(event: string | null) {
    switch (event?.toLowerCase()) {
        case 'created':
            return 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20 dark:text-emerald-400';
        case 'updated':
            return 'bg-sky-500/10 text-sky-600 border-sky-500/20 dark:text-sky-400';
        case 'deleted':
            return 'bg-red-500/10 text-red-600 border-red-500/20 dark:text-red-400';
        default:
            return 'bg-muted/50 text-muted-foreground border-border dark:bg-white/5 dark:border-white/10';
    }
}

function changedKeys(
    oldValues: Record<string, unknown> | null,
    newValues: Record<string, unknown> | null,
): string[] {
    const keys = new Set<string>([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return [...keys]
        .filter((k) => !HIDDEN_ACTIVITY_KEYS.has(k))
        .sort((a, b) => a.localeCompare(b));
}

function InfoRow({
    icon: Icon,
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-3 border-b border-border px-6 py-4 last:border-0 dark:border-white/5">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/50 text-muted-foreground dark:border-white/10 dark:bg-white/5">
                <Icon className="h-4 w-4" />
            </div>
            <div className="min-w-0">
                <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                    {label}
                </div>
                <div className="mt-1 truncate text-sm font-medium text-foreground/90">
                    {value}
                </div>
            </div>
        </div>
    );
}

export default function CompanyDetails({
    company,
    recent_activity,
    countries,
    currencies,
    timezones,
    document_settings,
    can_view_audit,
    company_documents,
    company_documents_can,
}: {
    company: Company;
    recent_activity: ActivityItem[];
    countries: Country[];
    currencies: Currency[];
    timezones?: string[];
    document_settings: CompanyDocumentSettings | null;
    can_view_audit: boolean;
    company_documents: {
        count: number;
        recent: CompanyDocumentSummaryItem[];
    } | null;
    company_documents_can: {
        view: boolean;
        upload: boolean;
        update: boolean;
        download: boolean;
        delete: boolean;
    };
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [documentSettingsOpen, setDocumentSettingsOpen] = useState(false);
    const [expandedActivity, setExpandedActivity] = useState<
        Record<number, boolean>
    >({});

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
        payroll_cycle:
            (company.payroll_cycle as 'monthly' | 'biweekly' | 'weekly') ??
            'monthly',
        working_days: company.working_days ?? [1, 2, 3, 4, 5],
        wps_agent_code: company.wps_agent_code ?? '',
        wps_mol_uid: company.wps_mol_uid ?? '',
        wps_employer_iban: company.wps_employer_iban ?? '',
        status:
            (company.status as 'active' | 'suspended' | 'inactive') ?? 'active',
    });

    const location =
        [company.city, company.country.code].filter(Boolean).join(', ') || '—';
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
        country: {
            id: company.country.id,
            code: company.country.code,
            name: company.country.name,
        },
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
        wps_employer_iban: company.wps_employer_iban,
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
                <DetailsHeader
                    title={company.name}
                    description="Full company profile and configuration."
                    backHref="/organization/companies"
                    backLabel="Back to companies"
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="h-12 rounded-xl border-border bg-muted/50 px-6 hover:bg-accent dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                                onClick={() => setEditOpen(true)}
                            >
                                Edit
                            </Button>
                            {website ? (
                                <Button
                                    className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                                    asChild
                                >
                                    <a
                                        href={website}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        <ExternalLink className="mr-2 h-4 w-4" />
                                        Website
                                    </a>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="overflow-hidden border-border bg-card backdrop-blur-xl lg:col-span-2 dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-4">
                            <div className="flex items-center gap-4">
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
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
                                        <Badge className="border-border bg-muted/50 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5">
                                            {company.status ?? '—'}
                                        </Badge>
                                        <Badge className="border-border bg-muted/50 text-[10px] font-bold tracking-wider text-muted-foreground uppercase dark:border-white/10 dark:bg-white/5">
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
                        <CardContent className="p-0">
                            <InfoRow
                                icon={Hash}
                                label="Slug"
                                value={company.slug || '—'}
                            />
                            <InfoRow
                                icon={Users}
                                label="Company size"
                                value={company.company_size ?? '—'}
                            />
                            <InfoRow
                                icon={IdCard}
                                label="Registration number"
                                value={company.registration_number ?? '—'}
                            />
                            <InfoRow
                                icon={ReceiptText}
                                label="Tax ID"
                                value={company.tax_id ?? '—'}
                            />
                            <InfoRow
                                icon={MapPin}
                                label="Country"
                                value={
                                    `${company.country.code ?? ''} ${company.country.name ?? ''}`.trim() ||
                                    '—'
                                }
                            />
                            <InfoRow
                                icon={Home}
                                label="Address"
                                value={company.address ?? '—'}
                            />
                            <div className="flex items-start gap-3 border-b border-border px-6 py-4 dark:border-white/5">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/50 text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                    <Phone className="h-4 w-4" />
                                </div>
                                <div>
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Contact
                                    </div>
                                    <div className="mt-1 space-y-1">
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Phone className="h-3.5 w-3.5 text-muted-foreground/80" />
                                            {company.phone ?? '—'}
                                        </div>
                                        <div className="flex items-center gap-2 text-sm font-medium">
                                            <Mail className="h-3.5 w-3.5 text-muted-foreground/80" />
                                            {company.email ?? '—'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-start gap-3 px-6 py-4">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/50 text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                    <Globe className="h-4 w-4" />
                                </div>
                                <div>
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Locale &amp; Currency
                                    </div>
                                    <div className="mt-1 space-y-1 text-sm font-medium">
                                        <div className="flex items-center gap-2">
                                            <Globe className="h-3.5 w-3.5 text-muted-foreground/80" />
                                            {company.timezone ?? '—'}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ShieldCheck className="h-3.5 w-3.5 text-muted-foreground/80" />
                                            {`${company.currency.code ?? ''} ${company.currency.name ?? ''}`.trim() ||
                                                '—'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-border bg-card backdrop-blur-xl dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-lg font-bold tracking-tight">
                                Payroll & Compliance
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <InfoRow
                                icon={CalendarClock}
                                label="Payroll cycle"
                                value={company.payroll_cycle ?? '—'}
                            />
                            <InfoRow
                                icon={CalendarDays}
                                label="Working days"
                                value={
                                    company.working_days?.length
                                        ? company.working_days.join(', ')
                                        : '—'
                                }
                            />
                            <InfoRow
                                icon={BadgeCheck}
                                label="WPS agent code"
                                value={company.wps_agent_code ?? '—'}
                            />
                            <InfoRow
                                icon={Fingerprint}
                                label="WPS MOL UID"
                                value={company.wps_mol_uid ?? '—'}
                            />
                            <InfoRow
                                icon={Fingerprint}
                                label="WPS employer IBAN"
                                value={company.wps_employer_iban ?? '—'}
                            />
                        </CardContent>
                    </Card>
                </div>

                {document_settings ? (
                    <Card className="mt-8 border-border bg-card backdrop-blur-xl dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <FileSignature className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Salary certificate
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        Document settings for printed salary
                                        certificates. Company identity
                                        (currency, timezone, and profile) is
                                        edited via Edit.
                                    </p>
                                </div>
                            </div>
                            {document_settings.can_update ? (
                                <Button
                                    className="h-11 shrink-0 rounded-xl"
                                    onClick={() =>
                                        setDocumentSettingsOpen(true)
                                    }
                                >
                                    <PenLine className="mr-2 h-4 w-4" />
                                    Configure
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent className="space-y-6 p-6">
                            {document_settings.using_legacy_signature ||
                            document_settings.using_legacy_stamp ? (
                                <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                                    {document_settings.using_legacy_signature &&
                                    document_settings.using_legacy_stamp
                                        ? 'Using legacy application signature and stamp until company assets are uploaded.'
                                        : document_settings.using_legacy_signature
                                          ? 'Using legacy application signature until a company signature is uploaded.'
                                          : 'Using legacy application stamp until a company stamp is uploaded.'}
                                </div>
                            ) : null}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Signature
                                    </div>
                                    <div className="flex h-24 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/30 dark:border-white/10 dark:bg-white/5">
                                        {document_settings.signature_url ? (
                                            <img
                                                src={
                                                    document_settings.signature_url
                                                }
                                                alt="Authorized signature"
                                                className="max-h-full max-w-full object-contain p-2"
                                            />
                                        ) : (
                                            <div className="flex flex-col items-center gap-1 text-muted-foreground/50">
                                                <PenLine className="h-5 w-5" />
                                                <span className="text-xs">
                                                    Not set
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Stamp
                                    </div>
                                    <div className="flex h-24 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/30 dark:border-white/10 dark:bg-white/5">
                                        {document_settings.stamp_url ? (
                                            <img
                                                src={
                                                    document_settings.stamp_url
                                                }
                                                alt="Company stamp"
                                                className="max-h-full max-w-full object-contain p-2"
                                            />
                                        ) : (
                                            <div className="flex flex-col items-center gap-1 text-muted-foreground/50">
                                                <ImageIcon className="h-5 w-5" />
                                                <span className="text-xs">
                                                    Not set
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Signatory
                                    </div>
                                    <p className="mt-1 text-sm font-medium text-foreground/90">
                                        {document_settings.signatory_name ??
                                            '—'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {document_settings.signatory_title ??
                                            '—'}
                                    </p>
                                </div>
                                <div>
                                    <div className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/80 uppercase">
                                        Effective period
                                    </div>
                                    <p className="mt-1 text-sm font-medium text-foreground/90">
                                        {document_settings.effective_from ||
                                        document_settings.effective_to
                                            ? `${document_settings.effective_from ?? '—'} → ${document_settings.effective_to ?? '—'}`
                                            : '—'}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {company_documents ? (
                    <Card className="mt-8 border-border bg-card backdrop-blur-xl dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border pb-4 dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <FolderOpen className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Company documents
                                    </CardTitle>
                                    <p className="text-xs text-muted-foreground">
                                        {company_documents.count} private
                                        compliance files
                                    </p>
                                </div>
                            </div>
                            <Button asChild>
                                <Link
                                    href={companyDocumentsIndex.url(company.id)}
                                >
                                    Manage documents
                                </Link>
                            </Button>
                        </CardHeader>
                        <CardContent className="p-0">
                            {company_documents.recent.length === 0 ? (
                                <div className="py-12 text-center text-sm text-muted-foreground">
                                    No documents uploaded yet.
                                </div>
                            ) : (
                                <div className="divide-y divide-border dark:divide-white/5">
                                    {company_documents.recent.map(
                                        (document) => (
                                            <div
                                                key={document.id}
                                                className="flex items-center gap-3 px-6 py-4"
                                            >
                                                <DocumentFileIcon
                                                    mimeType={
                                                        document.mime_type
                                                    }
                                                    fileName={
                                                        document.original_filename
                                                    }
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-semibold">
                                                        {document.title}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {
                                                            document.original_filename
                                                        }
                                                    </p>
                                                </div>
                                                <DocumentExpiryBadge
                                                    status={
                                                        document.expiry_status
                                                    }
                                                />
                                                {company_documents_can.download &&
                                                document.can_preview ? (
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        variant="ghost"
                                                        title="Preview"
                                                    >
                                                        <a
                                                            href={
                                                                document.preview_url
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </a>
                                                    </Button>
                                                ) : null}
                                                {company_documents_can.download ? (
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        variant="ghost"
                                                        title="Download"
                                                    >
                                                        <a
                                                            href={
                                                                document.download_url
                                                            }
                                                        >
                                                            <Download className="h-4 w-4" />
                                                        </a>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        ),
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                {can_view_audit ? (
                    <Card className="mt-8 border-border bg-card backdrop-blur-xl dark:border-white/5 dark:bg-white/5">
                        <CardHeader className="flex flex-row items-center justify-between border-b border-border pb-4 dark:border-white/5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary">
                                    <Activity className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Recent activity
                                    </CardTitle>
                                    <div className="text-[10px] text-muted-foreground/50">
                                        Latest changes for this company.
                                    </div>
                                </div>
                            </div>
                            <Badge className="border-border bg-muted/50 font-mono text-xs text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                {recent_activity.length}
                            </Badge>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent_activity.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 dark:border-white/10 dark:bg-white/[0.03]">
                                        <Activity className="h-5 w-5 text-muted-foreground/20" />
                                    </div>
                                    <p className="text-sm text-muted-foreground/50">
                                        No activity recorded yet.
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-border dark:divide-white/5">
                                    {recent_activity.map((a) => {
                                        const keys = changedKeys(
                                            a.old_values,
                                            a.new_values,
                                        );
                                        const isExpanded =
                                            expandedActivity[a.id] ?? false;
                                        const shown = isExpanded
                                            ? keys
                                            : keys.slice(0, 4);
                                        const showDescription =
                                            a.description
                                                .trim()
                                                .toLowerCase() !==
                                            (a.event ?? '')
                                                .trim()
                                                .toLowerCase();

                                        return (
                                            <div
                                                key={a.id}
                                                className="px-6 py-4 transition-colors hover:bg-muted/30 dark:hover:bg-white/[0.015]"
                                            >
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="min-w-0 flex-1 space-y-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                className={cn(
                                                                    'border px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase',
                                                                    eventColor(
                                                                        a.event,
                                                                    ),
                                                                )}
                                                            >
                                                                {a.event ??
                                                                    'event'}
                                                            </Badge>
                                                            <span className="text-sm font-semibold text-foreground/90">
                                                                {a.causer
                                                                    ?.name ??
                                                                    'System'}
                                                            </span>
                                                            {a.causer?.email ? (
                                                                <span className="text-xs text-muted-foreground/50">
                                                                    (
                                                                    {
                                                                        a.causer
                                                                            .email
                                                                    }
                                                                    )
                                                                </span>
                                                            ) : null}
                                                        </div>

                                                        {showDescription ? (
                                                            <p className="text-xs text-muted-foreground/70">
                                                                {a.description}
                                                            </p>
                                                        ) : null}

                                                        {shown.length > 0 ? (
                                                            <div className="flex flex-wrap gap-1.5 pt-0.5">
                                                                {shown.map(
                                                                    (k) => (
                                                                        <span
                                                                            key={
                                                                                k
                                                                            }
                                                                            className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5"
                                                                        >
                                                                            {formatActivityFieldLabel(
                                                                                k,
                                                                            )}
                                                                            :{' '}
                                                                            <span className="text-muted-foreground/70">
                                                                                {formatDisplayValue(
                                                                                    a
                                                                                        .old_values?.[
                                                                                        k
                                                                                    ],
                                                                                )}
                                                                            </span>{' '}
                                                                            →{' '}
                                                                            <span className="text-foreground/90">
                                                                                {formatDisplayValue(
                                                                                    a
                                                                                        .new_values?.[
                                                                                        k
                                                                                    ],
                                                                                )}
                                                                            </span>
                                                                        </span>
                                                                    ),
                                                                )}
                                                                {keys.length >
                                                                4 ? (
                                                                    <button
                                                                        type="button"
                                                                        className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-[11px] text-muted-foreground transition hover:bg-accent dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                                                        onClick={() =>
                                                                            setExpandedActivity(
                                                                                (
                                                                                    prev,
                                                                                ) => ({
                                                                                    ...prev,
                                                                                    [a.id]: !(
                                                                                        prev[
                                                                                            a
                                                                                                .id
                                                                                        ] ??
                                                                                        false
                                                                                    ),
                                                                                }),
                                                                            )
                                                                        }
                                                                    >
                                                                        {isExpanded
                                                                            ? 'Show less'
                                                                            : `+${keys.length - 4} more`}
                                                                    </button>
                                                                ) : null}
                                                            </div>
                                                        ) : null}
                                                    </div>

                                                    <div className="shrink-0 text-xs text-muted-foreground/50">
                                                        {formatDisplayDate(
                                                            a.created_at,
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                <CompanyFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    company={sheetCompany}
                    countries={countries}
                    currencies={currencies}
                    timezones={timezones}
                    form={form}
                    onSubmit={submit}
                />

                {document_settings ? (
                    <CompanyDocumentSettingsSheet
                        open={documentSettingsOpen}
                        onOpenChange={setDocumentSettingsOpen}
                        companyId={company.id}
                        companyName={company.name}
                        settings={document_settings}
                    />
                ) : null}
            </Main>
        </>
    );
}
