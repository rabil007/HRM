import { Link, router, useForm } from '@inertiajs/react';
import {
    Copy,
    Eye,
    FileStack,
    FileText,
    Layers,
    MoreHorizontal,
    Pencil,
    PenLine,
    Plus,
    Power,
    PowerOff,
    Send,
    Trash2,
    UploadCloud,
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
import { index as documentTypesIndex } from '@/routes/settings/master-data/document-types';
import { TemplateCreateChoiceDialog } from './components/template-create-choice-dialog';
import { TemplateDeleteDialog } from './components/template-delete-dialog';
import type { TemplateFormData } from './components/template-form-sheet';
import { TemplateFormSheet } from './components/template-form-sheet';
import { TemplatePdfUploadDialog } from './components/template-pdf-upload-dialog';
import { TemplatePreviewDialog } from './components/template-preview-dialog';
import { TemplateReplacePdfDialog } from './components/template-replace-pdf-dialog';
import { TemplatePdfDesignerDialog } from './designer/template-pdf-designer-dialog';
import type {
    CustomTemplate,
    DocumentTypeOption,
    MergeField,
    PlacementConfig,
    SystemTemplate,
    TemplatesPermissions,
    TemplateVersionSummary,
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
    // Dialog state
    const [isCreateChoiceOpen, setIsCreateChoiceOpen] = useState(false);
    const [isPdfUploadOpen, setIsPdfUploadOpen] = useState(false);
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

    // Phase 3B: Visual Designer & Replace PDF state
    const [isDesignerOpen, setIsDesignerOpen] = useState(false);
    const [designerTemplate, setDesignerTemplate] =
        useState<CustomTemplate | null>(null);
    const [designerVersion, setDesignerVersion] =
        useState<TemplateVersionSummary | null>(null);
    const [designerConfig, setDesignerConfig] =
        useState<PlacementConfig | null>(null);

    const [isReplacePdfOpen, setIsReplacePdfOpen] = useState(false);
    const [replacingTemplate, setReplacingTemplate] =
        useState<CustomTemplate | null>(null);
    const [replacingVersion, setReplacingVersion] =
        useState<TemplateVersionSummary | null>(null);

    const form = useForm<TemplateFormData>({
        name: '',
        description: '',
        document_type_id: null,
        content: '',
        status: 'draft',
    });

    const handleOpenCreateContent = () => {
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
                `/organization/documents/templates/${editingTemplate.id}`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        setIsFormOpen(false);
                        form.reset();
                    },
                },
            );
        } else {
            form.post('/organization/documents/templates', {
                preserveScroll: true,
                onSuccess: () => {
                    setIsFormOpen(false);
                    form.reset();
                },
            });
        }
    };

    const handleOpenDesigner = async (template: CustomTemplate) => {
        try {
            // Get or branch the single Draft version for editing
            const res = await fetch(
                `/organization/documents/templates/${template.id}/draft`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                },
            );

            if (!res.ok) {
                throw new Error('Failed to load editable template draft.');
            }

            const data = await res.json();
            setDesignerTemplate(template);
            setDesignerVersion(data.draft);
            setDesignerConfig(data.placement_config);
            setIsDesignerOpen(true);
        } catch (err) {
            console.error('Error opening designer:', err);
        }
    };

    const handleOpenReplacePdf = async (template: CustomTemplate) => {
        try {
            const res = await fetch(
                `/organization/documents/templates/${template.id}/draft`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                },
            );

            if (!res.ok) {
                throw new Error(
                    'Failed to prepare template draft for replacement.',
                );
            }

            const data = await res.json();
            setReplacingTemplate(template);
            setReplacingVersion(data.draft);
            setIsReplacePdfOpen(true);
        } catch (err) {
            console.error('Error opening replace PDF:', err);
        }
    };

    const handlePublishDraft = (
        template: CustomTemplate,
        versionId: number,
    ) => {
        router.post(
            `/organization/documents/templates/${template.id}/versions/${versionId}/publish`,
            {},
            { preserveScroll: true },
        );
    };

    const handleActivate = (template: CustomTemplate) => {
        router.post(
            `/organization/documents/templates/${template.id}/activate`,
            {},
            { preserveScroll: true },
        );
    };

    const handleDeactivate = (template: CustomTemplate) => {
        router.post(
            `/organization/documents/templates/${template.id}/deactivate`,
            {},
            { preserveScroll: true },
        );
    };

    const handleDuplicate = (template: CustomTemplate) => {
        router.post(
            `/organization/documents/templates/${template.id}/duplicate`,
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
            `/organization/documents/templates/${deletingTemplate.id}`,
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
                `/organization/documents/templates/${template.id}/preview`,
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
            const res = await fetch(
                '/organization/documents/templates/preview-draft',
                {
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
                },
            );

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
                        <Button
                            onClick={() => setIsCreateChoiceOpen(true)}
                            className="gap-1.5"
                        >
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
                            controlled merge fields and visual PDF overlays.
                        </p>
                    </div>

                    {can.view_templates ? (
                        customTemplates.length > 0 ? (
                            <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="w-[30%]">
                                                Template
                                            </TableHead>
                                            <TableHead className="w-[14%]">
                                                Format
                                            </TableHead>
                                            <TableHead className="w-[16%]">
                                                Document Type
                                            </TableHead>
                                            <TableHead className="w-[14%]">
                                                Status & Version
                                            </TableHead>
                                            <TableHead className="w-[14%]">
                                                Last Updated
                                            </TableHead>
                                            <TableHead className="w-[12%] text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {customTemplates.map((template) => {
                                            const isPdf =
                                                template.template_format ===
                                                'pdf_overlay';
                                            const hasDraft =
                                                template.draft_version !== null;
                                            const hasPublished =
                                                template.published_version !==
                                                null;

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
                                                        {isPdf ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-purple-500/30 bg-purple-500/10 text-xs font-medium text-purple-700 dark:text-purple-400"
                                                            >
                                                                PDF Template
                                                            </Badge>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="border-blue-500/30 bg-blue-500/10 text-xs font-medium text-blue-700 dark:text-blue-400"
                                                            >
                                                                Content
                                                            </Badge>
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
                                                        <div className="space-y-1">
                                                            <div className="flex items-center gap-1.5">
                                                                <Badge
                                                                    variant={
                                                                        template.status ===
                                                                        'active'
                                                                            ? 'default'
                                                                            : template.status ===
                                                                                'draft'
                                                                              ? 'secondary'
                                                                              : 'outline'
                                                                    }
                                                                    className="text-xs capitalize"
                                                                >
                                                                    {
                                                                        template.status_label
                                                                    }
                                                                </Badge>
                                                            </div>
                                                            <div className="text-[11px] text-muted-foreground">
                                                                {hasPublished && (
                                                                    <span>
                                                                        v
                                                                        {
                                                                            template
                                                                                .published_version
                                                                                ?.version
                                                                        }{' '}
                                                                        Published
                                                                    </span>
                                                                )}
                                                                {hasDraft && (
                                                                    <span
                                                                        className={
                                                                            hasPublished
                                                                                ? 'block font-medium text-amber-600 dark:text-amber-400'
                                                                                : 'font-medium'
                                                                        }
                                                                    >
                                                                        v
                                                                        {
                                                                            template
                                                                                .draft_version
                                                                                ?.version
                                                                        }{' '}
                                                                        Draft
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
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
                                                            {/* Direct Design button for PDF templates */}
                                                            {isPdf &&
                                                                can.update_templates && (
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-8 w-8 text-primary"
                                                                        title="Design Merge Fields"
                                                                        onClick={() =>
                                                                            handleOpenDesigner(
                                                                                template,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Layers className="h-4 w-4" />
                                                                        <span className="sr-only">
                                                                            Design
                                                                            Fields
                                                                        </span>
                                                                    </Button>
                                                                )}

                                                            {/* Preview button for Content templates */}
                                                            {!isPdf && (
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
                                                            )}

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
                                                                    <DropdownMenuContent
                                                                        align="end"
                                                                        className="w-48"
                                                                    >
                                                                        {can.update_templates && (
                                                                            <>
                                                                                {/* Content template edit */}
                                                                                {!isPdf && (
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
                                                                                            Content
                                                                                        </span>
                                                                                    </DropdownMenuItem>
                                                                                )}

                                                                                {/* PDF template visual design */}
                                                                                {isPdf && (
                                                                                    <>
                                                                                        <DropdownMenuItem
                                                                                            onClick={() =>
                                                                                                handleOpenDesigner(
                                                                                                    template,
                                                                                                )
                                                                                            }
                                                                                            className="gap-2"
                                                                                        >
                                                                                            <Layers className="h-3.5 w-3.5" />
                                                                                            <span>
                                                                                                Design
                                                                                                Fields
                                                                                            </span>
                                                                                        </DropdownMenuItem>
                                                                                        <DropdownMenuItem
                                                                                            onClick={() =>
                                                                                                handleOpenReplacePdf(
                                                                                                    template,
                                                                                                )
                                                                                            }
                                                                                            className="gap-2"
                                                                                        >
                                                                                            <UploadCloud className="h-3.5 w-3.5" />
                                                                                            <span>
                                                                                                Replace
                                                                                                PDF
                                                                                            </span>
                                                                                        </DropdownMenuItem>
                                                                                    </>
                                                                                )}

                                                                                {/* Publish Draft if available */}
                                                                                {hasDraft && (
                                                                                    <DropdownMenuItem
                                                                                        onClick={() =>
                                                                                            handlePublishDraft(
                                                                                                template,
                                                                                                template
                                                                                                    .draft_version!
                                                                                                    .id,
                                                                                            )
                                                                                        }
                                                                                        className="gap-2 font-medium text-emerald-600 dark:text-emerald-400"
                                                                                    >
                                                                                        <Send className="h-3.5 w-3.5" />
                                                                                        <span>
                                                                                            Publish
                                                                                            v
                                                                                            {
                                                                                                template
                                                                                                    .draft_version!
                                                                                                    .version
                                                                                            }
                                                                                        </span>
                                                                                    </DropdownMenuItem>
                                                                                )}

                                                                                {/* Activate / Deactivate */}
                                                                                {template.status ===
                                                                                'active' ? (
                                                                                    <DropdownMenuItem
                                                                                        onClick={() =>
                                                                                            handleDeactivate(
                                                                                                template,
                                                                                            )
                                                                                        }
                                                                                        className="gap-2"
                                                                                    >
                                                                                        <PowerOff className="h-3.5 w-3.5" />
                                                                                        <span>
                                                                                            Deactivate
                                                                                        </span>
                                                                                    </DropdownMenuItem>
                                                                                ) : template.published_version_id ? (
                                                                                    <DropdownMenuItem
                                                                                        onClick={() =>
                                                                                            handleActivate(
                                                                                                template,
                                                                                            )
                                                                                        }
                                                                                        className="gap-2"
                                                                                    >
                                                                                        <Power className="h-3.5 w-3.5" />
                                                                                        <span>
                                                                                            Activate
                                                                                        </span>
                                                                                    </DropdownMenuItem>
                                                                                ) : null}

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
                                description="Create reusable company letters, employment certificates, or upload branded PDF templates."
                                action={
                                    can.create_templates ? (
                                        <Button
                                            onClick={() =>
                                                setIsCreateChoiceOpen(true)
                                            }
                                            size="sm"
                                        >
                                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                                            <span>Create First Template</span>
                                        </Button>
                                    ) : null
                                }
                            />
                        )
                    ) : (
                        <Card className="border-border/60">
                            <CardContent className="pt-6 text-sm text-muted-foreground">
                                You do not have permission to view custom
                                templates.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* 2. System Generation Templates Section */}
                <div className="space-y-4">
                    <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground">
                            System Generation Templates
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Protected application templates built into OMS-HRM.
                            Configuration for e-signatures and company issuance
                            rules is managed in dedicated settings.
                        </p>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-[45%]">
                                        Template
                                    </TableHead>
                                    <TableHead className="w-[30%]">
                                        Type
                                    </TableHead>
                                    <TableHead className="w-[25%] text-right">
                                        Settings & E-Sign
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {systemTemplates.map((item) => (
                                    <TableRow key={item.key}>
                                        <TableCell>
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                    <FileStack className="size-4" />
                                                </div>
                                                <div>
                                                    <div className="font-medium text-foreground">
                                                        {item.label}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        Code:{' '}
                                                        <span className="font-mono">
                                                            {item.key}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant="secondary"
                                                className="text-xs font-normal"
                                            >
                                                Protected System
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {item.supports_esignature &&
                                                can.signature_placement ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={applicationSettings.url()}
                                                        >
                                                            <PenLine className="mr-1.5 size-3.5" />
                                                            Configure E-Sign
                                                        </Link>
                                                    </Button>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        Built-in formatting
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* 3. Help & Reference Section */}
                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="border-border/60">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-semibold">
                                Merge Field Reference
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Dynamic placeholders automatically populated
                                from employee and company data.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-xs text-muted-foreground">
                                Supported placeholders include{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                                    {'{{employee_name}}'}
                                </code>
                                ,{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                                    {'{{position_name}}'}
                                </code>
                                ,{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                                    {'{{salary_basic}}'}
                                </code>
                                , and{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground">
                                    {'{{company_name}}'}
                                </code>
                                . Unsupported merge fields are rejected upon
                                save.
                            </p>
                            <div className="flex items-center gap-2">
                                <Badge
                                    variant="outline"
                                    className="text-[11px]"
                                >
                                    {mergeFields.length} Merge Fields Available
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-sm font-semibold">
                                Document Types & Categorization
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Associate templates with master document types
                                for employee record tracking.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-xs text-muted-foreground">
                                Document types define expiration requirements,
                                compliance rules, and categorization across
                                employee profiles and company archives.
                            </p>
                            {can.document_types && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={documentTypesIndex.url()}>
                                        Manage Document Types
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Template Creation Choice Modal */}
            <TemplateCreateChoiceDialog
                open={isCreateChoiceOpen}
                onOpenChange={setIsCreateChoiceOpen}
                onSelectContent={handleOpenCreateContent}
                onSelectPdf={() => setIsPdfUploadOpen(true)}
            />

            {/* Upload PDF Template Dialog */}
            <TemplatePdfUploadDialog
                open={isPdfUploadOpen}
                onOpenChange={setIsPdfUploadOpen}
                documentTypes={documentTypes}
            />

            {/* Replace PDF Dialog */}
            <TemplateReplacePdfDialog
                open={isReplacePdfOpen}
                onOpenChange={setIsReplacePdfOpen}
                template={replacingTemplate}
                version={replacingVersion}
            />

            {/* Visual Merge-Field Placement Designer Dialog */}
            <TemplatePdfDesignerDialog
                open={isDesignerOpen}
                onOpenChange={setIsDesignerOpen}
                template={designerTemplate}
                version={designerVersion}
                initialConfig={designerConfig}
                mergeFields={mergeFields}
                onSaved={() => {
                    // Refresh data
                }}
            />

            {/* Content Template Editor Sheet */}
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

            {/* Sample-Only Preview Modal */}
            <TemplatePreviewDialog
                open={isPreviewOpen}
                onOpenChange={setIsPreviewOpen}
                title={previewData.title}
                contentHtml={previewData.contentHtml}
                unresolvedPlaceholders={previewData.unresolvedPlaceholders}
                isLoading={previewLoading}
            />

            {/* Delete Confirmation Modal */}
            <TemplateDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                template={deletingTemplate}
                onConfirm={handleConfirmDelete}
            />
        </Main>
    );
}
