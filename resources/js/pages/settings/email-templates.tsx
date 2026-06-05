import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText, Mail, Plus, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
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

    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [editing, setEditing] = useState<EmailTemplateItem | null>(null);
    const [deleting, setDeleting] = useState<EmailTemplateItem | null>(null);

    const form = useForm<FormState>(emptyForm());

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

    return (
        <>
            <Head title="Email templates" />

            <div className="space-y-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                Settings
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight">Email templates</h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Manage default subject and message text for outbound emails, grouped by
                            category. What you save is what users see when they pick a template—they
                            can still edit before sending.
                        </p>
                    </div>

                    <Button asChild variant="outline" className="rounded-xl">
                        <Link href="/settings/application">
                            <SlidersHorizontal className="mr-2 h-4 w-4" />
                            Application settings
                        </Link>
                    </Button>
                </div>

                {grouped.map((group) => (
                    <section key={group.value} className="space-y-4">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">{group.label}</h2>
                                <p className="text-sm text-muted-foreground">
                                    {group.templates.length}{' '}
                                    {group.templates.length === 1 ? 'template' : 'templates'}
                                </p>
                            </div>
                            {can.create ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-xl"
                                    onClick={() => openCreate(group.value)}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add {group.label.toLowerCase()} template
                                </Button>
                            ) : null}
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {group.templates.map((template) => (
                                <Card key={template.id} className="border-white/5 bg-white/5">
                                    <CardContent className="space-y-4 p-5">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="font-semibold">{template.label}</h3>
                                                    {template.is_default ? (
                                                        <Badge variant="secondary">Default</Badge>
                                                    ) : null}
                                                    {!template.enabled ? (
                                                        <Badge variant="outline">Disabled</Badge>
                                                    ) : null}
                                                </div>
                                                {template.slug === expiry_alert_template_slug ? (
                                                    <p className="text-sm text-muted-foreground">
                                                        Automated daily summary email with an HTML
                                                        table of expiring documents.
                                                    </p>
                                                ) : (
                                                    <p className="text-sm text-muted-foreground">
                                                        {template.subject}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex h-10 w-10 items-center justify-center rounded-2xl border border-blue-500/20 bg-blue-500/10">
                                                <Mail className="h-5 w-5 text-blue-600" />
                                            </div>
                                        </div>

                                        {template.slug === expiry_alert_template_slug &&
                                        template.dispatch_at ? (
                                            <p className="text-xs text-muted-foreground">
                                                <span className="font-medium text-zinc-300">
                                                    Daily dispatch:
                                                </span>{' '}
                                                {template.dispatch_at} ({scheduler_timezone})
                                            </p>
                                        ) : null}

                                        {template.to_preset || template.cc_preset ? (
                                            <div className="space-y-1 text-xs text-muted-foreground">
                                                {template.to_preset ? (
                                                    <p>
                                                        <span className="font-medium text-zinc-300">
                                                            To:
                                                        </span>{' '}
                                                        {template.to_preset}
                                                    </p>
                                                ) : null}
                                                {template.cc_preset ? (
                                                    <p>
                                                        <span className="font-medium text-zinc-300">
                                                            CC:
                                                        </span>{' '}
                                                        {template.cc_preset}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ) : null}

                                        {template.slug !== expiry_alert_template_slug ? (
                                            <p className="line-clamp-4 whitespace-pre-wrap text-sm text-muted-foreground">
                                                {template.body_html}
                                            </p>
                                        ) : null}

                                        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                            <span className="rounded-md bg-white/5 px-2 py-1">
                                                Slug: {template.slug}
                                            </span>
                                        </div>

                                        {can.update || can.delete ? (
                                            <div className="flex flex-wrap gap-2">
                                                {can.update ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="rounded-xl"
                                                        onClick={() => openEdit(template)}
                                                    >
                                                        Edit
                                                    </Button>
                                                ) : null}
                                                {can.delete &&
                                                template.slug !== expiry_alert_template_slug ? (
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
                                                        className="rounded-xl"
                                                        onClick={() => requestDelete(template)}
                                                    >
                                                        Delete
                                                    </Button>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </CardContent>
                                </Card>
                            ))}

                            {group.templates.length === 0 ? (
                                <Card className="border-dashed border-white/10 bg-transparent lg:col-span-2">
                                    <CardContent className="flex flex-col items-center justify-center gap-3 p-10 text-center">
                                        <FileText className="h-8 w-8 text-muted-foreground" />
                                        <p className="text-sm text-muted-foreground">
                                            No {group.label.toLowerCase()} templates yet.
                                        </p>
                                    </CardContent>
                                </Card>
                            ) : null}
                        </div>
                    </section>
                ))}
            </div>

            <MasterDataFormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                contentClassName="sm:max-w-lg"
                title={editing ? 'Edit email template' : 'New email template'}
                description="Subject and message are plain text. They prefill the send form; users can edit before sending."
                footer={
                    canMutateForm ? (
                        <MasterDataFormSheetFooter
                            onCancel={() => setSheetOpen(false)}
                            onSubmit={submit}
                            processing={form.processing}
                            submitLabel={editing ? 'Save changes' : 'Create template'}
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
                            <span className="font-mono text-zinc-400">schedule:run</span> every
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
                    <p className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-muted-foreground">
                        The automated expiry email uses a fixed HTML table (employee, document,
                        expiry date, days remaining). Configure recipients and daily dispatch time
                        below.
                    </p>
                )}

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
        </>
    );
}

EmailTemplatesSettings.layout = {};
