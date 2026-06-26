import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, Eye, FileText, Mail, Plus, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
    MasterDataActiveToggle,
    MasterDataField,
    MasterDataFormSheet,
    MasterDataFormSheetFooter,
    masterDataInputClass,
} from '@/components/settings/master-data-form-sheet';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    EmailTemplatePreviewDialog,
    type EmailTemplatePreviewTarget,
} from '@/features/settings/email-template-preview-dialog';
import { toast } from '@/lib/toast';

export type EmailTemplateItem = {
    id: number;
    slug: string;
    label: string;
    category: string;
    category_label: string;
    to_preset: string | null;
    cc_preset: string | null;
    dispatch_at: string | null;
    subject: string;
    body_html: string;
    include_company_footer: boolean;
    is_default: boolean;
    enabled: boolean;
    sort_order: number;
};

type Option = { value: string; label: string };

type Props = {
    templates: EmailTemplateItem[];
    categories: Option[];
    can: { create: boolean; update: boolean; delete: boolean };
    expiry_alert_template_slug: string;
    scheduler_timezone: string;
};

type FormState = {
    slug: string;
    label: string;
    category: string;
    to_preset: string;
    cc_preset: string;
    dispatch_at: string;
    subject: string;
    body_html: string;
    include_company_footer: boolean;
    is_default: boolean;
    enabled: boolean;
    sort_order: number;
};

const emptyForm = (category = 'document'): FormState => ({
    slug: '',
    label: '',
    category,
    to_preset: '',
    cc_preset: '',
    dispatch_at: '08:00',
    subject: 'Documents from Overseas Marine Services',
    body_html: 'Hello,\n\nPlease find the attached employee documents.\n\nThank you.',
    include_company_footer: true,
    is_default: false,
    enabled: true,
    sort_order: 0,
});

export default function EmailTemplatesSettings({
    templates,
    categories,
    can,
    expiry_alert_template_slug,
    scheduler_timezone,
}: Props) {
    const grouped = useMemo(() => {
        return categories.map((category) => ({
            ...category,
            templates: templates.filter((template) => template.category === category.value),
        }));
    }, [categories, templates]);

    const [activeCategory, setActiveCategory] = useState(categories[0]?.value || 'document');
    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [editing, setEditing] = useState<EmailTemplateItem | null>(null);
    const [deleting, setDeleting] = useState<EmailTemplateItem | null>(null);
    const [previewTarget, setPreviewTarget] = useState<EmailTemplatePreviewTarget | null>(null);

    const form = useForm<FormState>(emptyForm());

    const expiryAlertTemplate = useMemo(() => {
        return templates.find((t) => t.slug === expiry_alert_template_slug);
    }, [templates, expiry_alert_template_slug]);

    const openCreate = (category: string) => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyForm(category));
        setSheetOpen(true);
    };

    const openEdit = (template: EmailTemplateItem) => {
        setEditing(template);
        form.clearErrors();
        form.setData({
            slug: template.slug,
            label: template.label,
            category: template.category,
            to_preset: template.to_preset ?? '',
            cc_preset: template.cc_preset ?? '',
            dispatch_at: template.dispatch_at ?? '08:00',
            subject: template.subject,
            body_html: template.body_html,
            include_company_footer: template.include_company_footer,
            is_default: template.is_default,
            enabled: template.enabled,
            sort_order: template.sort_order,
        });
        setSheetOpen(true);
    };

    const canMutateForm = editing ? can.update : can.create;

    const submit = () => {
        if (!canMutateForm) {
            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(editing ? 'Template updated.' : 'Template created.');
                setSheetOpen(false);
            },
        };

        if (editing) {
            form.put(`/settings/application/email-templates/${editing.id}`, options);
        } else {
            form.post('/settings/application/email-templates', options);
        }
    };

    const confirmDelete = () => {
        if (!deleting || !can.delete) {
            return;
        }

        router.delete(`/settings/application/email-templates/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Template deleted.');
                setDeleteOpen(false);
                setDeleting(null);
            },
        });
    };

    const requestDelete = (template: EmailTemplateItem) => {
        setDeleting(template);
        setDeleteOpen(true);
    };

    const openSavedPreview = (template: EmailTemplateItem) => {
        setPreviewTarget({
            mode: 'saved',
            templateId: template.id,
            label: template.label,
            subject: template.subject,
        });
    };

    const openDraftPreview = () => {
        setPreviewTarget({
            mode: 'draft',
            slug: form.data.slug,
            label: form.data.label || 'Email template',
            subject: form.data.subject,
            bodyHtml: form.data.body_html,
            includeCompanyFooter: form.data.include_company_footer,
        });
    };

    const canPreviewDraft =
        form.data.slug !== expiry_alert_template_slug &&
        form.data.subject.trim() !== '' &&
        form.data.body_html.trim() !== '';

    const previewButton =
        canPreviewDraft ? (
            <Button
                type="button"
                variant="outline"
                className="h-11 w-full rounded-xl sm:w-auto"
                onClick={openDraftPreview}
            >
                <Eye className="mr-2 h-4 w-4" />
                Preview
            </Button>
        ) : editing !== null ? (
            <Button
                type="button"
                variant="outline"
                className="h-11 w-full rounded-xl sm:w-auto"
                onClick={() => openSavedPreview(editing)}
            >
                <Eye className="mr-2 h-4 w-4" />
                Preview
            </Button>
        ) : null;

    return (
        <>
            <Head title="Email templates" />

            <div className="space-y-8">
                {/* Premium Page Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                Settings
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight">Email templates</h1>
                        <p className="max-w-2xl text-sm text-muted-foreground leading-relaxed">
                            Manage default subject and message text for outbound emails, grouped by
                            category. What you save is what users see when they pick a template—they
                            can still edit before sending.
                        </p>
                    </div>

                    <Button asChild variant="outline" className="rounded-xl hover:bg-accent h-10 px-4 shrink-0">
                        <Link href="/settings/application">
                            <SlidersHorizontal className="mr-2 h-4 w-4" />
                            Application settings
                        </Link>
                    </Button>
                </div>

                {/* Dashboard Stats Panel */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card className="border-border/60 bg-muted/25 dark:border-white/5 dark:bg-white/[0.02] shadow-xs">
                        <CardContent className="flex items-center gap-4 p-5">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 border border-primary/20 text-primary">
                                <Mail className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-[10px] text-muted-foreground font-semibold uppercase tracking-wider">Total Templates</p>
                                <p className="text-2xl font-bold tracking-tight mt-0.5">{templates.length}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-muted/25 dark:border-white/5 dark:bg-white/[0.02] shadow-xs">
                        <CardContent className="flex items-center gap-4 p-5">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                                <CheckCircle2 className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-[10px] text-muted-foreground font-semibold uppercase tracking-wider">Active Templates</p>
                                <div className="flex items-baseline gap-2 mt-0.5">
                                    <p className="text-2xl font-bold tracking-tight">{templates.filter((t) => t.enabled).length}</p>
                                    {templates.filter((t) => !t.enabled).length > 0 && (
                                        <span className="text-[10px] text-muted-foreground">
                                            ({templates.filter((t) => !t.enabled).length} disabled)
                                        </span>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-muted/25 dark:border-white/5 dark:bg-white/[0.02] shadow-xs">
                        <CardContent className="flex items-center gap-4 p-5">
                            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400">
                                <Clock className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-[10px] text-muted-foreground font-semibold uppercase tracking-wider">Daily Expiry Alert</p>
                                <p className="text-sm font-semibold mt-1 truncate">
                                    {expiryAlertTemplate && expiryAlertTemplate.enabled
                                        ? `${expiryAlertTemplate.dispatch_at || '08:00'} (${scheduler_timezone})`
                                        : 'Disabled'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Tabs for Categories */}
                <Tabs value={activeCategory} onValueChange={setActiveCategory} className="space-y-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-border/40 pb-4">
                        <TabsList className="bg-muted/40 p-1 border border-border/40 rounded-xl h-11 self-start">
                            {categories.map((category) => {
                                const count = templates.filter((t) => t.category === category.value).length;
                                return (
                                    <TabsTrigger
                                        key={category.value}
                                        value={category.value}
                                        className="rounded-lg px-4 py-1.5 text-xs font-semibold data-[state=active]:bg-background dark:data-[state=active]:bg-white/10 dark:data-[state=active]:text-foreground data-[state=active]:shadow-xs"
                                    >
                                        {category.label}
                                        <Badge variant="secondary" className="ml-2 bg-muted-foreground/10 hover:bg-muted-foreground/15 text-[10px] px-1.5 py-0">
                                            {count}
                                        </Badge>
                                    </TabsTrigger>
                                );
                            })}
                        </TabsList>

                        {can.create && (
                            <Button
                                type="button"
                                variant="default"
                                className="rounded-xl h-10 px-5 shadow-xs self-start sm:self-auto"
                                onClick={() => openCreate(activeCategory)}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Template
                            </Button>
                        )}
                    </div>

                    {grouped.map((group) => (
                        <TabsContent key={group.value} value={group.value} className="space-y-6 outline-none">
                            <div className="grid gap-6 lg:grid-cols-2">
                                {group.templates.map((template) => (
                                    <Card
                                        key={template.id}
                                        className="group relative overflow-hidden border-border/60 bg-card hover:bg-muted/10 dark:border-white/5 dark:bg-white/[0.02] transition-all duration-300 hover:shadow-md hover:border-primary/20 hover:-translate-y-0.5"
                                    >
                                        {template.is_default && (
                                            <div className="absolute top-0 left-0 right-0 h-[3px] bg-gradient-to-r from-primary/80 to-blue-500/80" />
                                        )}

                                        <CardContent className="space-y-5 p-6">
                                            {/* Card Top Title Row */}
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="space-y-1.5 flex-1 min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="font-semibold text-lg text-foreground tracking-tight group-hover:text-primary transition-colors truncate">
                                                            {template.label}
                                                        </h3>
                                                        <div className="flex flex-wrap gap-1.5">
                                                            {template.is_default && (
                                                                <Badge variant="secondary" className="bg-primary/10 text-primary border-transparent text-[10px] px-2 py-0.5 rounded-md font-medium">
                                                                    Default
                                                                </Badge>
                                                            )}
                                                            {!template.enabled && (
                                                                <Badge variant="outline" className="text-muted-foreground/80 border-border text-[10px] px-2 py-0.5 rounded-md font-medium">
                                                                    Disabled
                                                                </Badge>
                                                            )}
                                                            {!template.include_company_footer && (
                                                                <Badge variant="outline" className="text-amber-600 dark:text-amber-400 border-amber-500/20 text-[10px] px-2 py-0.5 rounded-md font-medium">
                                                                    No Footer
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {template.slug === expiry_alert_template_slug ? (
                                                        <p className="text-xs text-muted-foreground leading-relaxed">
                                                            Automated daily summary email with an HTML table of expiring documents.
                                                        </p>
                                                    ) : (
                                                        <p className="text-xs text-muted-foreground font-mono truncate bg-muted/40 dark:bg-black/20 px-2 py-1 rounded border border-border/20 w-fit">
                                                            Subject: {template.subject}
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/5 text-primary shadow-inner">
                                                    <Mail className="h-5 w-5" />
                                                </div>
                                            </div>

                                            {/* Configs Row */}
                                            <div className="space-y-2.5 border-t border-border/40 pt-4">
                                                {template.slug === expiry_alert_template_slug && template.dispatch_at && (
                                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Clock className="h-3.5 w-3.5 text-muted-foreground/70" />
                                                        <span className="font-semibold text-foreground dark:text-zinc-300">Daily dispatch:</span>
                                                        <span>{template.dispatch_at} ({scheduler_timezone})</span>
                                                    </div>
                                                )}

                                                {template.to_preset && (
                                                    <div className="flex items-start gap-2 text-xs text-muted-foreground">
                                                        <span className="font-semibold text-foreground dark:text-zinc-300 mt-0.5 min-w-[24px]">To:</span>
                                                        <div className="flex flex-wrap gap-1">
                                                            {template.to_preset.split(',').map((email, idx) => (
                                                                <span key={idx} className="inline-flex items-center rounded-md bg-primary/5 px-2 py-0.5 font-mono text-[10px] text-primary border border-primary/10">
                                                                    {email.trim()}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}

                                                {template.cc_preset && (
                                                    <div className="flex items-start gap-2 text-xs text-muted-foreground">
                                                        <span className="font-semibold text-foreground dark:text-zinc-300 mt-0.5 min-w-[24px]">CC:</span>
                                                        <div className="flex flex-wrap gap-1">
                                                            {template.cc_preset.split(',').map((email, idx) => (
                                                                <span key={idx} className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-mono text-[10px] text-muted-foreground border border-border/40">
                                                                    {email.trim()}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Body HTML Frame */}
                                            {template.slug !== expiry_alert_template_slug && (
                                                <div className="relative rounded-xl border border-border/40 bg-muted/20 dark:bg-black/20 p-4">
                                                    <div className="absolute top-2.5 right-3 flex items-center gap-1">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-muted-foreground/30" />
                                                        <span className="w-1.5 h-1.5 rounded-full bg-muted-foreground/30" />
                                                        <span className="w-1.5 h-1.5 rounded-full bg-muted-foreground/30" />
                                                    </div>
                                                    <div className="text-[9px] font-bold text-muted-foreground/50 uppercase tracking-widest border-b border-border/20 pb-1.5 mb-2.5">
                                                        Template Body
                                                    </div>
                                                    <p className="line-clamp-4 whitespace-pre-wrap text-xs text-muted-foreground leading-relaxed">
                                                        {template.body_html}
                                                    </p>
                                                </div>
                                            )}

                                            {/* Bottom row */}
                                            <div className="flex items-center justify-between gap-3 border-t border-border/40 pt-4">
                                                <span className="text-[10px] font-mono text-muted-foreground/60 bg-muted/40 dark:bg-white/5 px-2 py-0.5 rounded border border-border/20">
                                                    Slug: {template.slug}
                                                </span>

                                                <div className="flex gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 rounded-lg hover:bg-primary/10 hover:text-primary transition-colors text-xs px-2.5 font-medium"
                                                        onClick={() => openSavedPreview(template)}
                                                    >
                                                        <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                        Preview
                                                    </Button>
                                                    {can.update && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-8 rounded-lg hover:bg-muted/80 text-xs px-2.5 font-medium border border-transparent hover:border-border/60"
                                                            onClick={() => openEdit(template)}
                                                        >
                                                            Edit
                                                        </Button>
                                                    )}
                                                    {can.delete && template.slug !== expiry_alert_template_slug && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive transition-colors text-xs px-2.5 font-medium"
                                                            onClick={() => requestDelete(template)}
                                                        >
                                                            Delete
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}

                                {group.templates.length === 0 && (
                                    <Card className="border-dashed border-border/80 dark:border-white/10 bg-transparent lg:col-span-2 shadow-none">
                                        <CardContent className="flex flex-col items-center justify-center gap-3 p-12 text-center">
                                            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground/60 border border-border/40">
                                                <FileText className="h-6 w-6" />
                                            </div>
                                            <div className="space-y-1">
                                                <p className="font-semibold text-foreground">No templates yet</p>
                                                <p className="text-xs text-muted-foreground max-w-xs">
                                                    Create a new {group.label.toLowerCase()} template to get started with automated messaging presets.
                                                </p>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        </TabsContent>
                    ))}
                </Tabs>
            </div>

            <MasterDataFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                contentClassName="sm:max-w-lg"
                title={editing ? 'Edit email template' : 'New email template'}
                description="Subject and message are plain text. They prefill the send form; users can edit before sending."
                footer={
                    canMutateForm || previewButton ? (
                        <MasterDataFormSheetFooter
                            leading={previewButton}
                            onCancel={() => setSheetOpen(false)}
                            onSubmit={submit}
                            processing={form.processing}
                            submitLabel={editing ? 'Save changes' : 'Create template'}
                            cancelLabel={canMutateForm ? 'Cancel' : 'Close'}
                            showSubmit={canMutateForm}
                        />
                    ) : null
                }
            >
                <MasterDataField id="label" label="Display label" error={form.errors.label}>
                    <Input
                        id="label"
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        disabled={!canMutateForm}
                        className={masterDataInputClass}
                    />
                </MasterDataField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <MasterDataField id="slug" label="Internal slug" error={form.errors.slug}>
                        <Input
                            id="slug"
                            value={form.data.slug}
                            onChange={(e) => form.setData('slug', e.target.value)}
                            disabled={!canMutateForm || editing !== null}
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField id="category" label="Category" error={form.errors.category}>
                        <Select
                            value={form.data.category}
                            onValueChange={(value) => form.setData('category', value)}
                            disabled={!canMutateForm}
                        >
                            <SelectTrigger id="category" className={masterDataInputClass}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </MasterDataField>
                </div>

                <MasterDataField
                    id="to_preset"
                    label="To preset (optional)"
                    error={form.errors.to_preset}
                >
                    <Input
                        id="to_preset"
                        type="text"
                        value={form.data.to_preset}
                        onChange={(e) => form.setData('to_preset', e.target.value)}
                        placeholder="recipient@example.com, backup@example.com"
                        disabled={!canMutateForm}
                        className={masterDataInputClass}
                    />
                    <p className="text-xs text-muted-foreground/80">
                        Comma-separated addresses. The first fills To in the send modal; any
                        extra addresses are added to CC.
                    </p>
                </MasterDataField>

                <MasterDataField
                    id="cc_preset"
                    label="CC preset (optional)"
                    error={form.errors.cc_preset}
                >
                    <Input
                        id="cc_preset"
                        type="text"
                        value={form.data.cc_preset}
                        onChange={(e) => form.setData('cc_preset', e.target.value)}
                        placeholder="cc1@example.com, cc2@example.com"
                        disabled={!canMutateForm}
                        className={masterDataInputClass}
                    />
                    <p className="text-xs text-muted-foreground/80">
                        Comma-separated CC addresses prefilled when this template is chosen.
                    </p>
                </MasterDataField>

                {form.data.slug === expiry_alert_template_slug ? (
                    <MasterDataField
                        id="dispatch_at"
                        label="Daily dispatch time"
                        error={form.errors.dispatch_at}
                    >
                        <Input
                            id="dispatch_at"
                            type="time"
                            value={form.data.dispatch_at}
                            onChange={(e) => form.setData('dispatch_at', e.target.value)}
                            disabled={!canMutateForm}
                            className={masterDataInputClass}
                        />
                        <p className="text-xs text-muted-foreground/80">
                            Runs once per day at this time using the timezone from Application
                            settings → Regional defaults ({scheduler_timezone}). Cron must call{' '}
                            <span className="font-mono text-muted-foreground">schedule:run</span> every
                            minute.
                        </p>
                    </MasterDataField>
                ) : null}

                {form.data.slug !== expiry_alert_template_slug ? (
                    <>
                        <MasterDataField
                            id="subject"
                            label="Email subject"
                            error={form.errors.subject}
                        >
                            <Input
                                id="subject"
                                value={form.data.subject}
                                onChange={(e) => form.setData('subject', e.target.value)}
                                disabled={!canMutateForm}
                                className={masterDataInputClass}
                            />
                        </MasterDataField>

                        <MasterDataField
                            id="body_html"
                            label="Message body"
                            error={form.errors.body_html}
                        >
                            <Textarea
                                id="body_html"
                                value={form.data.body_html}
                                onChange={(e) => form.setData('body_html', e.target.value)}
                                rows={10}
                                disabled={!canMutateForm}
                                className="min-h-[220px] resize-y rounded-xl border-border bg-card px-4 py-3 text-sm leading-relaxed transition-all focus-visible:ring-primary/40"
                            />
                            <p className="text-xs text-muted-foreground/80">
                                Plain text only. Line breaks are preserved when the message is sent.
                            </p>
                        </MasterDataField>
                    </>
                ) : (
                    <p className="rounded-xl border border-border/80 bg-muted/20 dark:border-white/10 dark:bg-white/5 px-4 py-3 text-sm text-muted-foreground">
                        The automated expiry email uses a fixed HTML table (employee, document,
                        expiry date, days remaining). Configure recipients and daily dispatch time
                        below.
                    </p>
                )}

                <MasterDataActiveToggle
                    checked={form.data.include_company_footer}
                    onCheckedChange={(checked) =>
                        form.setData('include_company_footer', checked)
                    }
                    title="Include company footer"
                    description="Adds your company logo, contact details, and certification bar at the bottom of the email."
                />

                <MasterDataField id="sort_order" label="Sort order" error={form.errors.sort_order}>
                    <Input
                        id="sort_order"
                        type="number"
                        min={0}
                        value={form.data.sort_order}
                        onChange={(e) =>
                            form.setData('sort_order', Number(e.target.value) || 0)
                        }
                        disabled={!canMutateForm}
                        className={masterDataInputClass}
                    />
                </MasterDataField>

                <MasterDataActiveToggle
                    checked={form.data.is_default}
                    onCheckedChange={(checked) => form.setData('is_default', checked)}
                    title="Default for category"
                    description="Used when a feature does not specify a template."
                />

                <MasterDataActiveToggle
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                    title="Enabled"
                    description="Disabled templates cannot be selected for sending."
                />
            </MasterDataFormSheet>

            <AlertDialog
                open={deleteOpen}
                onOpenChange={(open) => {
                    setDeleteOpen(open);

                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent className="glass-card">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete email template</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleting
                                ? `This will permanently delete “${deleting.label}”. Set another default in this category first if needed.`
                                : 'This will permanently delete this template.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="glass-card rounded-xl hover:bg-accent">
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction className="rounded-xl" onClick={confirmDelete}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <EmailTemplatePreviewDialog
                target={previewTarget}
                onOpenChange={(open) => {
                    if (!open) {
                        setPreviewTarget(null);
                    }
                }}
            />
        </>
    );
}

EmailTemplatesSettings.layout = {};
