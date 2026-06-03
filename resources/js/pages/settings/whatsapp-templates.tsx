import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText, MessageCircle, Plus, SlidersHorizontal } from 'lucide-react';
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
import {
    renderWhatsAppTemplatePreviewBody,
    WhatsAppDocumentTemplatePreview,
} from '@/features/settings/whatsapp-document-template-preview';
import { toast } from '@/lib/toast';

export type WhatsAppTemplateItem = {
    id: number;
    slug: string;
    label: string;
    category: string;
    category_label: string;
    meta_name: string;
    meta_language: string;
    header_type: string;
    header_type_label: string;
    body_preview: string;
    is_default: boolean;
    enabled: boolean;
    sort_order: number;
};

type Option = { value: string; label: string };

type Props = {
    templates: WhatsAppTemplateItem[];
    categories: Option[];
    header_types: Option[];
    language_options: Option[];
    can: { create: boolean; update: boolean; delete: boolean };
};

type FormState = {
    slug: string;
    label: string;
    category: string;
    meta_name: string;
    meta_language: string;
    header_type: string;
    body_preview: string;
    is_default: boolean;
    enabled: boolean;
    sort_order: number;
};

const emptyForm = (category = 'document'): FormState => ({
    slug: '',
    label: '',
    category,
    meta_name: '',
    meta_language: 'en',
    header_type: 'document',
    body_preview: 'Hello {{name}}, Please find the attached document from Overseas Marine Services. Thank you.',
    is_default: false,
    enabled: true,
    sort_order: 0,
});

export default function WhatsAppTemplatesSettings({
    templates,
    categories,
    header_types,
    language_options,
    can,
}: Props) {
    const grouped = useMemo(() => {
        return categories.map((category) => ({
            ...category,
            templates: templates.filter((template) => template.category === category.value),
        }));
    }, [categories, templates]);

    const [sheetOpen, setSheetOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [editing, setEditing] = useState<WhatsAppTemplateItem | null>(null);
    const [deleting, setDeleting] = useState<WhatsAppTemplateItem | null>(null);
    const [previewSampleName, setPreviewSampleName] = useState('John Smith');
    const [previewFileName, setPreviewFileName] = useState('Employee Document.pdf');

    const form = useForm<FormState>(emptyForm());

    const previewBody = useMemo(
        () => renderWhatsAppTemplatePreviewBody(form.data.body_preview, previewSampleName),
        [form.data.body_preview, previewSampleName],
    );

    const openCreate = (category: string) => {
        setEditing(null);
        form.clearErrors();
        form.setData(emptyForm(category));
        setSheetOpen(true);
    };

    const openEdit = (template: WhatsAppTemplateItem) => {
        setEditing(template);
        form.clearErrors();
        form.setData({
            slug: template.slug,
            label: template.label,
            category: template.category,
            meta_name: template.meta_name,
            meta_language: template.meta_language,
            header_type: template.header_type,
            body_preview: template.body_preview,
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
            form.put(`/settings/application/whatsapp-templates/${editing.id}`, options);
        } else {
            form.post('/settings/application/whatsapp-templates', options);
        }
    };

    const confirmDelete = () => {
        if (!deleting || !can.delete) {
            return;
        }

        router.delete(`/settings/application/whatsapp-templates/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Template deleted.');
                setDeleteOpen(false);
                setDeleting(null);
            },
        });
    };

    const requestDelete = (template: WhatsAppTemplateItem) => {
        setDeleting(template);
        setDeleteOpen(true);
    };

    return (
        <>
            <Head title="WhatsApp templates" />

            <div className="space-y-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-2">
                        <div className="flex items-center gap-2">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                Settings
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight">WhatsApp templates</h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Manage approved Meta templates by category. Document sends pick from
                            document templates; future payroll features will use payroll templates.
                        </p>
                    </div>

                    <Button asChild variant="outline" className="rounded-xl">
                        <Link href="/settings/application?tab=whatsapp">
                            <SlidersHorizontal className="mr-2 h-4 w-4" />
                            WhatsApp credentials
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
                                                <p className="font-mono text-xs text-muted-foreground">
                                                    {template.meta_name} · {template.meta_language}
                                                </p>
                                            </div>
                                            <div className="flex h-10 w-10 items-center justify-center rounded-2xl border border-green-500/20 bg-green-500/10">
                                                <MessageCircle className="h-5 w-5 text-green-600" />
                                            </div>
                                        </div>

                                        <p className="line-clamp-3 text-sm text-muted-foreground">
                                            {renderWhatsAppTemplatePreviewBody(
                                                template.body_preview,
                                                'Employee Name',
                                            )}
                                        </p>

                                        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                            <span className="rounded-md bg-white/5 px-2 py-1">
                                                Slug: {template.slug}
                                            </span>
                                            <span className="rounded-md bg-white/5 px-2 py-1">
                                                Header: {template.header_type_label}
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
                                                {can.delete ? (
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
                title={editing ? 'Edit WhatsApp template' : 'New WhatsApp template'}
                description="Match the exact template name and language approved in Meta WhatsApp Manager."
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

                <div className="grid gap-5 sm:grid-cols-2">
                    <MasterDataField
                        id="meta_name"
                        label="Meta template name"
                        error={form.errors.meta_name}
                    >
                        <Input
                            id="meta_name"
                            value={form.data.meta_name}
                            onChange={(e) => form.setData('meta_name', e.target.value)}
                            disabled={!canMutateForm}
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField
                        id="meta_language"
                        label="Meta language"
                        error={form.errors.meta_language}
                    >
                        <Select
                            value={form.data.meta_language}
                            onValueChange={(value) => form.setData('meta_language', value)}
                            disabled={!canMutateForm}
                        >
                            <SelectTrigger id="meta_language" className={masterDataInputClass}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {language_options.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </MasterDataField>
                </div>

                <MasterDataField id="header_type" label="Header type" error={form.errors.header_type}>
                    <Select
                        value={form.data.header_type}
                        onValueChange={(value) => form.setData('header_type', value)}
                        disabled={!canMutateForm}
                    >
                        <SelectTrigger id="header_type" className={masterDataInputClass}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {header_types.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </MasterDataField>

                <MasterDataField
                    id="body_preview"
                    label="Body preview text"
                    error={form.errors.body_preview}
                >
                    <Textarea
                        id="body_preview"
                        value={form.data.body_preview}
                        onChange={(e) => form.setData('body_preview', e.target.value)}
                        rows={4}
                        disabled={!canMutateForm}
                        className="min-h-[100px] rounded-xl border-border bg-card px-4 py-3 transition-all focus-visible:ring-primary/40"
                    />
                    <p className="text-xs text-muted-foreground/80">
                        Use {'{{name}}'} for the employee name variable in Meta template {'{{1}}'}.
                    </p>
                </MasterDataField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <MasterDataField id="preview_sample_name" label="Preview sample name">
                        <Input
                            id="preview_sample_name"
                            value={previewSampleName}
                            onChange={(e) => setPreviewSampleName(e.target.value)}
                            className={masterDataInputClass}
                        />
                    </MasterDataField>

                    <MasterDataField id="preview_file_name" label="Preview file name">
                        <Input
                            id="preview_file_name"
                            value={previewFileName}
                            onChange={(e) => setPreviewFileName(e.target.value)}
                            className={masterDataInputClass}
                        />
                    </MasterDataField>
                </div>

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

                <WhatsAppDocumentTemplatePreview
                    templateName={form.data.meta_name || 'template_name'}
                    templateLanguage={form.data.meta_language || 'en'}
                    bodyText={previewBody}
                    sampleFileName={previewFileName}
                />

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
                        <AlertDialogTitle>Delete WhatsApp template</AlertDialogTitle>
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

WhatsAppTemplatesSettings.layout = {};
