import type { InertiaFormProps } from '@inertiajs/react';
import { Eye, Plus, Sparkles } from 'lucide-react';
import { useRef, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { insertMergeField } from '../lib/insert-merge-field';
import type { CustomTemplate, DocumentTypeOption, MergeField } from '../types';

export type TemplateFormData = {
    name: string;
    description: string;
    document_type_id: string | number | null;
    content: string;
    status: 'draft' | 'active' | 'inactive';
};

export function TemplateFormSheet({
    open,
    onOpenChange,
    template,
    documentTypes,
    mergeFields,
    form,
    onSubmit,
    onPreviewDraft,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    documentTypes: DocumentTypeOption[];
    mergeFields: MergeField[];
    form: InertiaFormProps<TemplateFormData>;
    onSubmit: () => void;
    onPreviewDraft: (name: string, content: string) => void;
}) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [selectedCategory, setSelectedCategory] =
        useState<string>('Employee');

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

        // Restore focus and cursor position after React update
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

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border/60 p-6 pb-4">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {template
                            ? 'Edit Document Template'
                            : 'New Document Template'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground">
                        {template
                            ? 'Update document template content and settings.'
                            : 'Create a reusable custom document template for this company.'}
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSubmit();
                    }}
                    className="flex flex-1 flex-col overflow-hidden"
                >
                    <div className="flex-1 space-y-5 overflow-y-auto p-6">
                        {/* Template Name */}
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="template_name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
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
                            {form.errors.name && (
                                <p className="text-xs text-destructive">
                                    {form.errors.name}
                                </p>
                            )}
                        </div>

                        {/* Document Type and Status row */}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="document_type_id"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Document Type
                                </Label>
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
                                {form.errors.document_type_id && (
                                    <p className="text-xs text-destructive">
                                        {form.errors.document_type_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Status{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(val) =>
                                        form.setData(
                                            'status',
                                            val as
                                                | 'draft'
                                                | 'active'
                                                | 'inactive',
                                        )
                                    }
                                    placeholder="Select status"
                                >
                                    <AppSelectItem value="draft">
                                        Draft
                                    </AppSelectItem>
                                    <AppSelectItem value="active">
                                        Active
                                    </AppSelectItem>
                                    <AppSelectItem value="inactive">
                                        Inactive
                                    </AppSelectItem>
                                </AppSelect>
                                {form.errors.status && (
                                    <p className="text-xs text-destructive">
                                        {form.errors.status}
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Description */}
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="description"
                                className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
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
                            {form.errors.description && (
                                <p className="text-xs text-destructive">
                                    {form.errors.description}
                                </p>
                            )}
                        </div>

                        {/* Merge Field Selector */}
                        {mergeFields.length > 0 && (
                            <div className="space-y-2.5 rounded-xl border border-border/80 bg-muted/20 p-3.5">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                                        <Sparkles className="h-3.5 w-3.5 text-primary" />
                                        <span>Insert Merge Field</span>
                                    </div>
                                    <div className="flex gap-1">
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
                                <div className="flex max-h-24 flex-wrap gap-1.5 overflow-y-auto pr-1">
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
                                    Click any chip to insert at cursor. Only
                                    allowed merge fields can be saved.
                                </p>
                            </div>
                        )}

                        {/* Content */}
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between">
                                <Label
                                    htmlFor="content"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    Document Content{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 gap-1 text-xs text-muted-foreground hover:text-foreground"
                                    onClick={() =>
                                        onPreviewDraft(
                                            form.data.name,
                                            form.data.content,
                                        )
                                    }
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
                                rows={14}
                                placeholder="Enter document body text using {{placeholders}} where appropriate..."
                                className="min-h-[260px] resize-y font-mono text-xs leading-relaxed"
                            />
                            {form.errors.content && (
                                <p className="text-xs text-destructive">
                                    {form.errors.content}
                                </p>
                            )}
                        </div>
                    </div>

                    <SheetFooter className="gap-2 border-t border-border/60 bg-muted/10 p-6">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Saving...'
                                : template
                                  ? 'Update Template'
                                  : 'Create Template'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
