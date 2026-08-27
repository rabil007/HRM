import { Link, router, useForm } from '@inertiajs/react';
import {
    Copy,
    Eye,
    FileStack,
    FileText,
    MoreHorizontal,
    Pencil,
    PenLine,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';
import { edit as applicationSettings } from '@/routes/application';
import { generate } from '@/routes/organization/documents';
import templatesRoute from '@/routes/organization/documents/templates';
import { index as documentTypesIndex } from '@/routes/settings/master-data/document-types';
import { TemplateDeleteDialog } from './components/template-delete-dialog';
import type { TemplateFormData } from './components/template-form-sheet';
import { TemplateFormSheet } from './components/template-form-sheet';
import { TemplatePreviewDialog } from './components/template-preview-dialog';
import type {
    CustomTemplate,
    DocumentTypeOption,
    MergeField,
    SystemTemplate,
    TemplatesPermissions,
} from './types';

function getCsrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function formatDate(isoString: string | null): string {
    if (!isoString) {
        return '—';
    }

    try {
        const date = new Date(isoString);

        return date.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    } catch {
        return '—';
    }
}

export function DocumentsTemplatesContent({
    customTemplates,
    mergeFields,
    documentTypes,
    systemTemplates,
    can,
}: {
    customTemplates: CustomTemplate[];
    mergeFields: MergeField[];
    documentTypes: DocumentTypeOption[];
    systemTemplates: SystemTemplate[];
    can: TemplatesPermissions;
}) {
    const [isFormOpen, setIsFormOpen] = useState(false);
    const [editingTemplate, setEditingTemplate] =
        useState<CustomTemplate | null>(null);

    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [deletingTemplate, setDeletingTemplate] =
        useState<CustomTemplate | null>(null);

    const [isPreviewOpen, setIsPreviewOpen] = useState(false);
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
        name: '',
        description: '',
        document_type_id: null,
        content: '',
        status: 'draft',
    });

    const handleOpenCreate = () => {
        setEditingTemplate(null);
        form.reset();
        form.clearErrors();
        form.setData({
            name: '',
            description: '',
            document_type_id: null,
            content: '',
            status: 'draft',
        });
        setIsFormOpen(true);
    };

    const handleOpenEdit = (template: CustomTemplate) => {
        setEditingTemplate(template);
        form.clearErrors();
        form.setData({
            name: template.name,
            description: template.description ?? '',
            document_type_id: template.document_type_id,
            content: template.content,
            status: template.status,
        });
        setIsFormOpen(true);
    };

    const handleFormSubmit = () => {
        if (editingTemplate) {
            form.put(
                templatesRoute.update.url({ template: editingTemplate.id }),
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setIsFormOpen(false);
                        form.reset();
                    },
                },
            );
        } else {
            form.post(templatesRoute.store.url(), {
                preserveScroll: true,
                onSuccess: () => {
                    setIsFormOpen(false);
                    form.reset();
                },
            });
        }
    };

    const handleDuplicate = (template: CustomTemplate) => {
        router.post(
            templatesRoute.duplicate.url({ template: template.id }),
            {},
            { preserveScroll: true },
        );
    };

    const handleOpenDelete = (template: CustomTemplate) => {
        setDeletingTemplate(template);
        setIsDeleteOpen(true);
    };

    const handleConfirmDelete = () => {
        if (!deletingTemplate) {
            return;
        }

        router.delete(
            templatesRoute.destroy.url({ template: deletingTemplate.id }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsDeleteOpen(false);
                    setDeletingTemplate(null);
                },
            },
        );
    };

    const handlePreviewStored = async (template: CustomTemplate) => {
        setIsPreviewOpen(true);
        setPreviewLoading(true);
        setPreviewData({
            title: template.name,
            contentHtml: '',
            unresolvedPlaceholders: [],
        });

        try {
            const res = await fetch(
                templatesRoute.preview.url({ template: template.id }),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!res.ok) {
                throw new Error('Failed to load preview');
            }

            const data = await res.json();
            setPreviewData({
                title: data.name,
                contentHtml: data.content_html,
                unresolvedPlaceholders: data.unresolved_placeholders || [],
            });
        } catch {
            setPreviewData({
                title: template.name,
                contentHtml:
                    '<p class="text-destructive">Failed to generate preview.</p>',
                unresolvedPlaceholders: [],
            });
        } finally {
            setPreviewLoading(false);
        }
    };

    const handlePreviewDraft = async (name: string, content: string) => {
        setIsPreviewOpen(true);
        setPreviewLoading(true);
        setPreviewData({
            title: name || 'Draft Template Preview',
            contentHtml: '',
            unresolvedPlaceholders: [],
        });

        try {
            const res = await fetch(templatesRoute.previewDraft.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    name: name || 'Draft Template Preview',
                    content,
                }),
            });

            if (!res.ok) {
                throw new Error('Failed to generate draft preview');
            }

            const data = await res.json();
            setPreviewData({
                title: data.name,
                contentHtml: data.content_html,
                unresolvedPlaceholders: data.unresolved_placeholders || [],
            });
        } catch {
            setPreviewData({
                title: name || 'Draft Template Preview',
                contentHtml:
                    '<p class="text-destructive">Failed to generate draft preview.</p>',
                unresolvedPlaceholders: [],
            });
        } finally {
            setPreviewLoading(false);
        }
    };

    return (
        <Main>
            <PageHeader
                title="Templates"
                description="Create and manage reusable company document templates."
                right={
                    can.create_templates ? (
                        <Button onClick={handleOpenCreate} className="gap-1.5">
                            <Plus className="h-4 w-4" />
                            <span>New Template</span>
                        </Button>
                    ) : null
                }
            />

            <DocumentsModuleNav />

            <div className="space-y-8">
                {/* 1. Company Custom Templates Section */}
                <div className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground">
                            Custom Templates
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Company-owned reusable templates supporting
                            controlled merge fields.
                        </p>
                    </div>

                    {can.view_templates ? (
                        customTemplates.length > 0 ? (
                            <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="w-[35%]">
                                                Template
                                            </TableHead>
                                            <TableHead className="w-[20%]">
                                                Document Type
                                            </TableHead>
                                            <TableHead className="w-[12%]">
                                                Status
                                            </TableHead>
                                            <TableHead className="w-[20%]">
                                                Last Updated
                                            </TableHead>
                                            <TableHead className="w-[13%] text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {customTemplates.map((template) => {
                                            const statusVariant =
                                                template.status === 'active'
                                                    ? 'default'
                                                    : template.status ===
                                                        'draft'
                                                      ? 'secondary'
                                                      : 'outline';

                                            return (
                                                <TableRow key={template.id}>
                                                    <TableCell>
                                                        <div className="font-medium text-foreground">
                                                            {template.name}
                                                        </div>
                                                        {template.description && (
                                                            <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                                {
                                                                    template.description
                                                                }
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {template.document_type_title ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="text-xs font-normal"
                                                            >
                                                                {
                                                                    template.document_type_title
                                                                }
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                (General)
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={
                                                                statusVariant
                                                            }
                                                            className="text-xs capitalize"
                                                        >
                                                            {
                                                                template.status_label
                                                            }
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="text-xs text-foreground">
                                                            {formatDate(
                                                                template.updated_at,
                                                            )}
                                                        </div>
                                                        {template.updated_by_name && (
                                                            <div className="text-[11px] text-muted-foreground">
                                                                by{' '}
                                                                {
                                                                    template.updated_by_name
                                                                }
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-8 w-8"
                                                                title="Preview"
                                                                onClick={() =>
                                                                    handlePreviewStored(
                                                                        template,
                                                                    )
                                                                }
                                                            >
                                                                <Eye className="h-4 w-4 text-muted-foreground" />
                                                                <span className="sr-only">
                                                                    Preview
                                                                </span>
                                                            </Button>

                                                            {(can.update_templates ||
                                                                can.delete_templates) && (
                                                                <DropdownMenu>
                                                                    <DropdownMenuTrigger
                                                                        asChild
                                                                    >
                                                                        <Button
                                                                            type="button"
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="h-8 w-8"
                                                                        >
                                                                            <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
                                                                            <span className="sr-only">
                                                                                Actions
                                                                            </span>
                                                                        </Button>
                                                                    </DropdownMenuTrigger>
                                                                    <DropdownMenuContent align="end">
                                                                        {can.update_templates && (
                                                                            <>
                                                                                <DropdownMenuItem
                                                                                    onClick={() =>
                                                                                        handleOpenEdit(
                                                                                            template,
                                                                                        )
                                                                                    }
                                                                                    className="gap-2"
                                                                                >
                                                                                    <Pencil className="h-3.5 w-3.5" />
                                                                                    <span>
                                                                                        Edit
                                                                                    </span>
                                                                                </DropdownMenuItem>
                                                                                <DropdownMenuItem
                                                                                    onClick={() =>
                                                                                        handleDuplicate(
                                                                                            template,
                                                                                        )
                                                                                    }
                                                                                    className="gap-2"
                                                                                >
                                                                                    <Copy className="h-3.5 w-3.5" />
                                                                                    <span>
                                                                                        Duplicate
                                                                                    </span>
                                                                                </DropdownMenuItem>
                                                                            </>
                                                                        )}
                                                                        {can.update_templates &&
                                                                            can.delete_templates && (
                                                                                <DropdownMenuSeparator />
                                                                            )}
                                                                        {can.delete_templates && (
                                                                            <DropdownMenuItem
                                                                                onClick={() =>
                                                                                    handleOpenDelete(
                                                                                        template,
                                                                                    )
                                                                                }
                                                                                className="gap-2 text-destructive focus:text-destructive"
                                                                            >
                                                                                <Trash2 className="h-3.5 w-3.5" />
                                                                                <span>
                                                                                    Delete
                                                                                </span>
                                                                            </DropdownMenuItem>
                                                                        )}
                                                                    </DropdownMenuContent>
                                                                </DropdownMenu>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        ) : (
                            <EmptyState
                                icon={
                                    <FileText className="mx-auto mb-2 h-8 w-8 text-muted-foreground/60" />
                                }
                                title="No custom templates yet."
                                description="Create reusable company letters, employment certificates, and notices."
                                action={
                                    can.create_templates ? (
                                        <Button
                                            onClick={handleOpenCreate}
                                            className="gap-1.5"
                                        >
                                            <Plus className="h-4 w-4" />
                                            <span>Create first template</span>
                                        </Button>
                                    ) : null
                                }
                            />
                        )
                    ) : (
                        <div className="rounded-xl border border-border/80 bg-muted/20 p-6 text-sm text-muted-foreground">
                            You do not have permission to view company custom
                            templates.
                        </div>
                    )}
                </div>

                {/* 2. System Generation Templates and Shortcuts */}
                <div className="space-y-4 border-t border-border/60 pt-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground">
                            System Templates &amp; Master Data
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Protected application renderers and classification
                            master data.
                        </p>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        {systemTemplates.length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-2">
                                        <FileStack className="h-4 w-4 text-muted-foreground" />
                                        <CardTitle>
                                            System generation templates
                                        </CardTitle>
                                    </div>
                                    <CardDescription>
                                        Protected system renderers used by
                                        Generate &amp; Send. Layout is
                                        code-owned and not editable.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {systemTemplates.map((template) => (
                                        <div
                                            key={template.key}
                                            className="flex items-start justify-between gap-3 rounded-xl border border-border/60 px-3 py-2.5"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-foreground">
                                                    {template.label}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Protected salary PDF
                                                    renderer
                                                </p>
                                            </div>
                                            <Badge variant="secondary">
                                                System
                                            </Badge>
                                        </div>
                                    ))}
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Link href={generate.url()}>
                                            Open Generate &amp; Send
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}

                        {can.document_types ? (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-2">
                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                        <CardTitle>Document Types</CardTitle>
                                    </div>
                                    <CardDescription>
                                        Classification and required-document
                                        compliance configuration. These are not
                                        generation templates.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Link href={documentTypesIndex.url()}>
                                            Open Document Types
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}

                        {can.signature_placement ? (
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-2">
                                        <PenLine className="h-4 w-4 text-muted-foreground" />
                                        <CardTitle>
                                            Signature placement
                                        </CardTitle>
                                    </div>
                                    <CardDescription>
                                        Salary Declaration signature and date
                                        placement remains in Application
                                        settings.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Link
                                            href={applicationSettings.url({
                                                query: { tab: 'esign' },
                                            })}
                                        >
                                            Open signature placement
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </div>

            {/* Create / Edit Sheet */}
            <TemplateFormSheet
                open={isFormOpen}
                onOpenChange={setIsFormOpen}
                template={editingTemplate}
                documentTypes={documentTypes}
                mergeFields={mergeFields}
                form={form}
                onSubmit={handleFormSubmit}
                onPreviewDraft={handlePreviewDraft}
            />

            {/* Preview Dialog */}
            <TemplatePreviewDialog
                open={isPreviewOpen}
                onOpenChange={setIsPreviewOpen}
                title={previewData.title}
                contentHtml={previewData.contentHtml}
                unresolvedPlaceholders={previewData.unresolvedPlaceholders}
                isLoading={previewLoading}
            />

            {/* Delete Dialog */}
            <TemplateDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                template={deletingTemplate}
                onConfirm={handleConfirmDelete}
            />
        </Main>
    );
}
