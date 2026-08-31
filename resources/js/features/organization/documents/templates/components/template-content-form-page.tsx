import { router, useForm } from '@inertiajs/react';
import { Eye, Plus, Sparkles } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { templates } from '@/routes/organization/documents';
import {
    previewDraft as previewDraftTemplate,
    store as storeTemplate,
    update as updateTemplate,
} from '@/routes/organization/documents/templates';
import { insertMergeField } from '../lib/insert-merge-field';
import type { CustomTemplate, DocumentTypeOption, MergeField } from '../types';
import type { TemplateFormData } from './template-form-sheet';
import { TemplatePreviewDialog } from './template-preview-dialog';

export function TemplateContentFormPage({
    template = null,
    documentTypes,
    mergeFields,
}: {
    template?: CustomTemplate | null;
    documentTypes: DocumentTypeOption[];
    mergeFields: MergeField[];
}) {
    const isEdit = template !== null;
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [selectedCategory, setSelectedCategory] = useState('Employee');
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewData, setPreviewData] = useState<{
        title: string;
        contentHtml: string;
        unresolvedPlaceholders: string[];
    }>({
        title: '',
        contentHtml: '',
        unresolvedPlaceholders: [],
    });

    const form = useForm<TemplateFormData>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        document_type_id: template?.document_type_id ?? null,
        content:
            template?.draft_version?.content ??
            template?.published_version?.content ??
            template?.content ??
            '',
    });

    const categories = Array.from(new Set(mergeFields.map((f) => f.category)));
    const filteredFields = mergeFields.filter(
        (f) => f.category === selectedCategory,
    );

    const handleInsertField = (fieldKey: string) => {
        const textarea = textareaRef.current;
        const selection = textarea
            ? { start: textarea.selectionStart, end: textarea.selectionEnd }
            : null;

        const { newContent, newCursorPosition } = insertMergeField(
            form.data.content,
            fieldKey,
            selection,
        );

        form.setData('content', newContent);

        setTimeout(() => {
            if (textarea) {
                textarea.focus();
                textarea.setSelectionRange(
                    newCursorPosition,
                    newCursorPosition,
                );
            }
        }, 0);
    };

    const handlePreviewDraft = async () => {
        const title = form.data.name || 'Draft preview';
        setPreviewLoading(true);
        setPreviewOpen(true);
        setPreviewData({
            title,
            contentHtml: '',
            unresolvedPlaceholders: [],
        });

        try {
            const response = await fetch(previewDraftTemplate.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
                body: JSON.stringify({
                    name: title,
                    content: form.data.content,
                }),
            });

            if (!response.ok) {
                throw new Error('Preview failed.');
            }

            const data = await response.json();
            setPreviewData({
                title: data.name ?? title,
                contentHtml: data.content_html ?? '',
                unresolvedPlaceholders: data.unresolved_placeholders || [],
            });
        } catch {
            setPreviewData({
                title,
                contentHtml:
                    '<p class="text-destructive">Could not generate draft preview.</p>',
                unresolvedPlaceholders: [],
            });
        } finally {
            setPreviewLoading(false);
        }
    };

    const submit = () => {
        if (isEdit && template) {
            form.put(updateTemplate.url({ template: template.id }), {
                preserveScroll: true,
            });

            return;
        }

        form.post(storeTemplate.url(), {
            preserveScroll: true,
        });
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Documents"
                title={
                    isEdit ? 'Edit Content Template' : 'New Content Template'
                }
                description={
                    isEdit
                        ? 'Update document template content and settings.'
                        : 'Create a reusable custom document template for this company.'
                }
                backHref={templates.url()}
                backLabel="Back to Templates"
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-12 rounded-xl px-6"
                            onClick={() => router.visit(templates.url())}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="h-12 rounded-xl px-6"
                            onClick={submit}
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Template'
                                  : 'Create Template'}
                        </Button>
                    </div>
                }
            />

            <Card className="mx-auto max-w-4xl overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="space-y-5 p-6">
                    <div className="space-y-1.5">
                        <Label htmlFor="template_name">
                            Template Name{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="template_name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="e.g. Employment Verification Letter"
                            className="h-10"
                        />
                        {form.errors.name ? (
                            <p className="text-xs text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="document_type_id">Document Type</Label>
                        <AppSelect
                            value={
                                form.data.document_type_id
                                    ? String(form.data.document_type_id)
                                    : 'none'
                            }
                            onValueChange={(val) =>
                                form.setData(
                                    'document_type_id',
                                    val === 'none' ? null : Number(val),
                                )
                            }
                            placeholder="Select document type"
                        >
                            <AppSelectItem value="none">
                                (None / General)
                            </AppSelectItem>
                            {documentTypes.map((type) => (
                                <AppSelectItem
                                    key={type.id}
                                    value={String(type.id)}
                                >
                                    {type.title}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.document_type_id ? (
                            <p className="text-xs text-destructive">
                                {form.errors.document_type_id}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="description">
                            Description (optional)
                        </Label>
                        <Input
                            id="description"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="Brief note on when this template is used"
                            className="h-10"
                        />
                        {form.errors.description ? (
                            <p className="text-xs text-destructive">
                                {form.errors.description}
                            </p>
                        ) : null}
                    </div>

                    {mergeFields.length > 0 ? (
                        <div className="space-y-2.5 rounded-xl border border-border/80 bg-muted/20 p-3.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                                    <Sparkles className="h-3.5 w-3.5 text-primary" />
                                    <span>Insert Merge Field</span>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {categories.map((cat) => (
                                        <button
                                            key={cat}
                                            type="button"
                                            onClick={() =>
                                                setSelectedCategory(cat)
                                            }
                                            className={`rounded-md px-2 py-0.5 text-[11px] font-medium transition-colors ${
                                                selectedCategory === cat
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'bg-muted text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {cat}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto pr-1">
                                {filteredFields.map((field) => (
                                    <button
                                        key={field.key}
                                        type="button"
                                        onClick={() =>
                                            handleInsertField(field.key)
                                        }
                                        className="inline-flex items-center gap-1 rounded-md border border-border/60 bg-background px-2 py-1 font-mono text-xs transition-colors hover:border-primary/50 hover:bg-primary/5"
                                        title={`${field.label} (e.g. ${field.sample})`}
                                    >
                                        <Plus className="h-3 w-3 text-muted-foreground" />
                                        <span>{field.key}</span>
                                    </button>
                                ))}
                            </div>
                            <p className="text-[11px] text-muted-foreground">
                                Click any chip to insert at cursor. Only allowed
                                merge fields can be saved.
                            </p>
                        </div>
                    ) : null}

                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between gap-2">
                            <Label htmlFor="content">
                                Document Content{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 gap-1 text-xs text-muted-foreground hover:text-foreground"
                                onClick={handlePreviewDraft}
                                disabled={!form.data.content.trim()}
                            >
                                <Eye className="h-3.5 w-3.5" />
                                Preview Draft
                            </Button>
                        </div>
                        <Textarea
                            id="content"
                            ref={textareaRef}
                            value={form.data.content}
                            onChange={(e) =>
                                form.setData('content', e.target.value)
                            }
                            rows={18}
                            placeholder="Enter document body text using {{placeholders}} where appropriate..."
                            className="min-h-[360px] resize-y font-mono text-xs leading-relaxed"
                        />
                        {form.errors.content ? (
                            <p className="text-xs text-destructive">
                                {form.errors.content}
                            </p>
                        ) : null}
                    </div>
                </CardContent>
            </Card>

            <TemplatePreviewDialog
                open={previewOpen}
                onOpenChange={setPreviewOpen}
                title={previewData.title}
                contentHtml={previewData.contentHtml}
                unresolvedPlaceholders={previewData.unresolvedPlaceholders}
                isLoading={previewLoading}
            />
        </Main>
    );
}
