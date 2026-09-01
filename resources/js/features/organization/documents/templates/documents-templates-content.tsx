import { Link, router } from '@inertiajs/react';
import {
    Copy,
    FileStack,
    Layers,
    MoreHorizontal,
    PenLine,
    Power,
    PowerOff,
    Search,
    Send,
    Settings2,
    Trash2,
    UploadCloud,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { cn } from '@/lib/utils';
import { edit as applicationSettings } from '@/routes/application';
import {
    activate as activateTemplate,
    deactivate as deactivateTemplate,
    design as designTemplate,
    destroy as destroyTemplate,
    draft as draftTemplate,
    duplicate as duplicateTemplate,
} from '@/routes/organization/documents/templates';
import { pdf as createPdfTemplate } from '@/routes/organization/documents/templates/create';
import { publish as publishTemplateVersion } from '@/routes/organization/documents/templates/versions';
import { TemplateAutomationSheet } from './components/template-automation-sheet';
import { TemplateDeleteDialog } from './components/template-delete-dialog';
import { TemplateReplacePdfDialog } from './components/template-replace-pdf-dialog';
import type {
    AutomationPresetOption,
    CustomTemplate,
    DocumentTypeOption,
    MergeField,
    SystemTemplate,
    TemplatesPermissions,
    TemplateVersionSummary,
} from './types';

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
    workflowPresets,
    signingPresets,
    systemTemplates,
    can,
}: {
    customTemplates: CustomTemplate[];
    mergeFields: MergeField[];
    documentTypes: DocumentTypeOption[];
    workflowPresets: AutomationPresetOption[];
    signingPresets: AutomationPresetOption[];
    systemTemplates: SystemTemplate[];
    can: TemplatesPermissions;
}) {
    // Dialog state
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [deletingTemplate, setDeletingTemplate] =
        useState<CustomTemplate | null>(null);

    const [isReplacePdfOpen, setIsReplacePdfOpen] = useState(false);
    const [replacingTemplate, setReplacingTemplate] =
        useState<CustomTemplate | null>(null);
    const [replacingVersion, setReplacingVersion] =
        useState<TemplateVersionSummary | null>(null);

    const [isAutomationOpen, setIsAutomationOpen] = useState(false);
    const [automationTemplate, setAutomationTemplate] =
        useState<CustomTemplate | null>(null);

    const [isActionLoading, setIsActionLoading] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    // Filter state
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<
        'all' | 'active' | 'draft' | 'inactive'
    >('all');

    const handleOpenReplacePdf = (template: CustomTemplate) => {
        setActionError(null);

        if (template.draft_version) {
            setReplacingTemplate(template);
            setReplacingVersion(template.draft_version);
            setIsReplacePdfOpen(true);

            return;
        }

        setIsActionLoading(true);
        router.post(
            draftTemplate.url({ template: template.id }),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    setIsActionLoading(false);
                    const updatedTemplates =
                        (page.props.custom_templates as
                            | CustomTemplate[]
                            | undefined) ?? [];
                    const matched = updatedTemplates.find(
                        (t) => t.id === template.id,
                    );

                    if (matched?.draft_version) {
                        setReplacingTemplate(matched);
                        setReplacingVersion(matched.draft_version);
                        setIsReplacePdfOpen(true);
                    }
                },
                onError: (err) => {
                    setIsActionLoading(false);
                    const msg =
                        (Object.values(err)[0] as string) ||
                        'Failed to prepare template draft for replacement.';
                    setActionError(msg);
                },
            },
        );
    };

    const handleOpenAutomation = (template: CustomTemplate) => {
        setAutomationTemplate(template);
        setIsAutomationOpen(true);
    };

    const handlePublishDraft = (
        template: CustomTemplate,
        versionId: number,
    ) => {
        router.post(
            publishTemplateVersion.url({
                template: template.id,
                version: versionId,
            }),
            {},
            { preserveScroll: true },
        );
    };

    const handleActivate = (template: CustomTemplate) => {
        router.post(
            activateTemplate.url({ template: template.id }),
            {},
            { preserveScroll: true },
        );
    };

    const handleDeactivate = (template: CustomTemplate) => {
        router.post(
            deactivateTemplate.url({ template: template.id }),
            {},
            { preserveScroll: true },
        );
    };

    const handleDuplicate = (template: CustomTemplate) => {
        router.post(
            duplicateTemplate.url({ template: template.id }),
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

        router.delete(destroyTemplate.url({ template: deletingTemplate.id }), {
            preserveScroll: true,
            onSuccess: () => {
                setIsDeleteOpen(false);
                setDeletingTemplate(null);
            },
        });
    };

    // Derived / filtered values
    const activeCount = customTemplates.filter(
        (t) => t.status === 'active',
    ).length;
    const pendingDraftCount = customTemplates.filter(
        (t) => t.draft_version !== null,
    ).length;

    const filteredTemplates = customTemplates.filter((t) => {
        const matchesSearch =
            searchQuery === '' ||
            t.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
            (t.description?.toLowerCase().includes(searchQuery.toLowerCase()) ??
                false);
        const matchesStatus =
            statusFilter === 'all' || t.status === statusFilter;

        return matchesSearch && matchesStatus;
    });

    return (
        <Main>
            <PageHeader
                title="Templates"
                description="Create reusable documents for Generate & Send."
                right={
                    can.create_templates ? (
                        <Button asChild className="gap-1.5">
                            <Link href={createPdfTemplate.url()}>
                                <UploadCloud className="h-4 w-4" />
                                <span>Upload PDF</span>
                            </Link>
                        </Button>
                    ) : null
                }
            />

            <div className="space-y-8">
                {/* 1. Company Custom Templates Section */}
                <div className="space-y-4">
                    {/* Stats strip */}
                    {can.view_templates && customTemplates.length > 0 && (
                        <div className="grid grid-cols-3 gap-3">
                            <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-card px-4 py-3">
                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <FileStack className="size-4" />
                                </div>
                                <div>
                                    <div className="text-lg leading-none font-bold text-foreground">
                                        {customTemplates.length}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                        Total
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-card px-4 py-3">
                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                    <Power className="size-4" />
                                </div>
                                <div>
                                    <div className="text-lg leading-none font-bold text-foreground">
                                        {activeCount}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                        Active
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-card px-4 py-3">
                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                                    <Send className="size-4" />
                                </div>
                                <div>
                                    <div className="text-lg leading-none font-bold text-foreground">
                                        {pendingDraftCount}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                        Pending Draft
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Error banner */}
                    {actionError && (
                        <div className="flex items-center justify-between rounded-lg border border-destructive/20 bg-destructive/10 px-4 py-2.5 text-xs text-destructive">
                            <span>{actionError}</span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-5"
                                onClick={() => setActionError(null)}
                            >
                                <X className="size-3" />
                            </Button>
                        </div>
                    )}

                    {can.view_templates ? (
                        customTemplates.length > 0 ? (
                            <>
                                {/* Search + Filter Bar */}
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <div className="relative flex-1">
                                        <Search className="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                        <input
                                            type="text"
                                            placeholder="Search templates…"
                                            value={searchQuery}
                                            onChange={(e) =>
                                                setSearchQuery(e.target.value)
                                            }
                                            className="h-9 w-full rounded-lg border border-border bg-background pr-3 pl-9 text-sm outline-none placeholder:text-muted-foreground focus:border-primary focus:ring-1 focus:ring-primary/20"
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {(
                                            [
                                                { value: 'all', label: 'All' },
                                                {
                                                    value: 'active',
                                                    label: 'Active',
                                                },
                                                {
                                                    value: 'draft',
                                                    label: 'Draft',
                                                },
                                                {
                                                    value: 'inactive',
                                                    label: 'Inactive',
                                                },
                                            ] as const
                                        ).map(({ value, label }) => (
                                            <button
                                                key={value}
                                                type="button"
                                                onClick={() =>
                                                    setStatusFilter(value)
                                                }
                                                className={cn(
                                                    'h-9 rounded-lg px-3 text-xs font-medium transition-colors',
                                                    statusFilter === value
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'border border-border bg-background text-muted-foreground hover:bg-muted',
                                                )}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {/* Card Grid or no-results state */}
                                {filteredTemplates.length > 0 ? (
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        {filteredTemplates.map((template) => {
                                            const hasDraft =
                                                template.draft_version !== null;
                                            const hasPublished =
                                                template.published_version !==
                                                null;

                                            return (
                                                <div
                                                    key={template.id}
                                                    className="group relative flex flex-col overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs transition-all hover:border-border hover:shadow-md"
                                                >
                                                    {/* Top colour accent bar */}
                                                    <div className="h-0.5 w-full shrink-0 bg-purple-500" />

                                                    {/* Card body */}
                                                    <div className="flex flex-1 flex-col p-4">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="flex min-w-0 items-start gap-3">
                                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
                                                                    <Layers className="size-4" />
                                                                </div>
                                                                <div className="min-w-0">
                                                                    <div className="truncate text-sm leading-tight font-semibold text-foreground">
                                                                        {
                                                                            template.name
                                                                        }
                                                                    </div>
                                                                    {template.description ? (
                                                                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                                            {
                                                                                template.description
                                                                            }
                                                                        </p>
                                                                    ) : (
                                                                        <p className="mt-0.5 text-xs text-muted-foreground/50 italic">
                                                                            No
                                                                            description
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            {/* Actions overflow menu */}
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
                                                                            className="h-7 w-7 shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
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
                                                                                <DropdownMenuItem
                                                                                    asChild
                                                                                    className="gap-2"
                                                                                >
                                                                                    <Link
                                                                                        href={designTemplate.url(
                                                                                            {
                                                                                                template:
                                                                                                    template.id,
                                                                                            },
                                                                                        )}
                                                                                    >
                                                                                        <Layers className="h-3.5 w-3.5" />
                                                                                        <span>
                                                                                            Design
                                                                                            Template
                                                                                        </span>
                                                                                    </Link>
                                                                                </DropdownMenuItem>
                                                                                <DropdownMenuSeparator />
                                                                                <DropdownMenuItem
                                                                                    disabled={
                                                                                        isActionLoading
                                                                                    }
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

                                                                                {/* Publish draft */}
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

                                                                                <DropdownMenuItem
                                                                                    onClick={() =>
                                                                                        handleOpenAutomation(
                                                                                            template,
                                                                                        )
                                                                                    }
                                                                                    className="gap-2"
                                                                                >
                                                                                    <Settings2 className="h-3.5 w-3.5" />
                                                                                    <span>
                                                                                        After
                                                                                        generation
                                                                                    </span>
                                                                                </DropdownMenuItem>

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

                                                        {/* Badges */}
                                                        <div className="mt-3 flex flex-wrap items-center gap-1.5">
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
                                                                className="text-[11px] capitalize"
                                                            >
                                                                {
                                                                    template.status_label
                                                                }
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-purple-500/30 bg-purple-500/10 text-[11px] font-medium text-purple-700 dark:text-purple-400"
                                                            >
                                                                PDF Template
                                                            </Badge>
                                                            {template.document_type_title && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-[11px] font-normal"
                                                                >
                                                                    {
                                                                        template.document_type_title
                                                                    }
                                                                </Badge>
                                                            )}
                                                        </div>

                                                        {/* Version info */}
                                                        {(hasPublished ||
                                                            hasDraft) && (
                                                            <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                                {hasPublished && (
                                                                    <span className="text-[11px] text-muted-foreground">
                                                                        v
                                                                        {
                                                                            template
                                                                                .published_version
                                                                                ?.version
                                                                        }{' '}
                                                                        published
                                                                    </span>
                                                                )}
                                                                {hasDraft && (
                                                                    <span
                                                                        className={cn(
                                                                            'text-[11px] font-medium',
                                                                            hasPublished
                                                                                ? 'text-amber-600 dark:text-amber-400'
                                                                                : 'text-muted-foreground',
                                                                        )}
                                                                    >
                                                                        v
                                                                        {
                                                                            template
                                                                                .draft_version
                                                                                ?.version
                                                                        }{' '}
                                                                        draft
                                                                    </span>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>

                                                    {/* Card footer with quick actions */}
                                                    <div className="flex items-center justify-between border-t border-border/60 bg-muted/20 px-4 py-2.5">
                                                        <div className="text-[11px] text-muted-foreground">
                                                            {formatDate(
                                                                template.updated_at,
                                                            )}
                                                            {template.updated_by_name && (
                                                                <span>
                                                                    {' '}
                                                                    ·{' '}
                                                                    {
                                                                        template.updated_by_name
                                                                    }
                                                                </span>
                                                            )}
                                                        </div>

                                                        <div className="flex items-center gap-0.5">
                                                            {/* Design button for PDF */}
                                                            {can.update_templates && (
                                                                <Button
                                                                    asChild
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-7 w-7 text-primary"
                                                                    title="Design Template"
                                                                >
                                                                    <Link
                                                                        href={designTemplate.url(
                                                                            {
                                                                                template:
                                                                                    template.id,
                                                                            },
                                                                        )}
                                                                    >
                                                                        <Layers className="h-3.5 w-3.5" />
                                                                        <span className="sr-only">
                                                                            Design
                                                                            Template
                                                                        </span>
                                                                    </Link>
                                                                </Button>
                                                            )}

                                                            {/* Publish draft quick-action */}
                                                            {hasDraft &&
                                                                can.update_templates && (
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7 text-emerald-600 dark:text-emerald-400"
                                                                        title={`Publish v${template.draft_version!.version}`}
                                                                        onClick={() =>
                                                                            handlePublishDraft(
                                                                                template,
                                                                                template
                                                                                    .draft_version!
                                                                                    .id,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Send className="h-3.5 w-3.5" />
                                                                        <span className="sr-only">
                                                                            Publish
                                                                            Draft
                                                                        </span>
                                                                    </Button>
                                                                )}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    /* No results after filtering */
                                    <div className="rounded-xl border border-border/80 bg-muted/30 p-10 text-center backdrop-blur-xl dark:border-white/5 dark:bg-white/5">
                                        <Search className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                                        <div className="text-sm font-semibold text-foreground/90">
                                            No templates match your filters.
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Try adjusting your search or
                                            clearing the filters.
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSearchQuery('');
                                                setStatusFilter('all');
                                            }}
                                            className="mt-4 text-xs font-medium text-primary underline-offset-2 hover:underline"
                                        >
                                            Clear filters
                                        </button>
                                    </div>
                                )}
                            </>
                        ) : (
                            <EmptyState
                                icon={
                                    <FileStack className="mx-auto mb-2 h-8 w-8 text-muted-foreground/60" />
                                }
                                title="No company templates yet."
                                description="Upload a PDF template for Generate & Send."
                                action={
                                    can.create_templates ? (
                                        <Button asChild size="sm">
                                            <Link
                                                href={createPdfTemplate.url()}
                                            >
                                                <UploadCloud className="mr-1.5 h-3.5 w-3.5" />
                                                <span>Upload PDF</span>
                                            </Link>
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
                        <h2 className="text-base font-semibold tracking-tight text-foreground">
                            Built-in Templates
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Ready-to-use templates provided by the system.
                        </p>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="w-[50%]">
                                        Template
                                    </TableHead>
                                    <TableHead className="w-[25%]">
                                        Type
                                    </TableHead>
                                    <TableHead className="w-[25%] text-right">
                                        Settings & E-Sign
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {systemTemplates.map((item) => (
                                    <TableRow key={item.key} className="group">
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary transition-colors group-hover:bg-primary/15">
                                                    <FileStack className="size-4" />
                                                </div>
                                                <div>
                                                    <div className="font-medium text-foreground">
                                                        {item.label}
                                                    </div>
                                                    <div className="font-mono text-[11px] text-muted-foreground">
                                                        {item.key}
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
                                                            href={applicationSettings.url(
                                                                {
                                                                    query: {
                                                                        tab: 'esign',
                                                                    },
                                                                },
                                                            )}
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
            </div>

            {/* Replace PDF Dialog */}
            <TemplateReplacePdfDialog
                open={isReplacePdfOpen}
                onOpenChange={setIsReplacePdfOpen}
                template={replacingTemplate}
                version={replacingVersion}
            />

            <TemplateAutomationSheet
                open={isAutomationOpen}
                onOpenChange={(open) => {
                    setIsAutomationOpen(open);

                    if (!open) {
                        setAutomationTemplate(null);
                    }
                }}
                template={automationTemplate}
                workflowPresets={workflowPresets}
                signingPresets={signingPresets}
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
