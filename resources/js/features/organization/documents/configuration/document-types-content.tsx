import { router, useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import {
    destroy as destroyDocumentType,
    store as storeDocumentType,
    update as updateDocumentType,
} from '@/actions/App/Http/Controllers/Settings/MasterData/DocumentTypeController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { ListTableCrudActions } from '@/components/list-table-actions';
import {
    MobileRecordCard,
    MobileRecordList,
} from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DocumentTypeFormSheet } from '@/features/organization/documents/configuration/document-type-form-sheet';
import { DocumentTypeImportDialog } from '@/features/organization/documents/configuration/document-type-import-dialog';
import {
    documentTypeAppliesToLabel,
    documentTypeExpiryLabel,
    documentTypeRequirementStatus,
    initialDocumentTypeForm,
    requirementToFormData,
} from '@/features/organization/documents/configuration/types';
import type {
    DepartmentOption,
    DocumentTypeRow,
    PositionOption,
    ProjectOption,
    RankOption,
} from '@/features/organization/documents/configuration/types';
import { useSettingsMasterDataCan } from '@/hooks/use-has-permission';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import { configuration as documentsConfiguration } from '@/routes/organization/documents';
import { show as documentTypeShow } from '@/routes/organization/documents/configuration';
import type { PaginationMeta } from '@/types/pagination';

export function DocumentTypesContent({
    documentTypes,
    pagination,
    search = '',
    departments = [],
    positions = [],
    ranks = [],
    projects = [],
    openDocumentType = null,
}: {
    documentTypes: DocumentTypeRow[];
    pagination: PaginationMeta;
    search?: string;
    departments?: DepartmentOption[];
    positions?: PositionOption[];
    ranks?: RankOption[];
    projects?: ProjectOption[];
    openDocumentType?: DocumentTypeRow | null;
}) {
    const can = useSettingsMasterDataCan('document-types');

    const list = useServerPaginationFilters({
        url: documentsConfiguration.url(),
        search,
        filters: {},
        pagination,
    });

    const detailHref = (documentTypeId: number) =>
        documentTypeShow.url(documentTypeId);

    const [sheetOpen, setSheetOpen] = useState(openDocumentType !== null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [current, setCurrent] = useState<DocumentTypeRow | null>(
        openDocumentType,
    );
    const form = useForm(
        openDocumentType
            ? requirementToFormData(openDocumentType)
            : initialDocumentTypeForm,
    );

    const clearEditQuery = () => {
        if (typeof window === 'undefined') {
            return;
        }

        const params = new URLSearchParams(window.location.search);

        if (!params.has('edit')) {
            return;
        }

        router.get(
            documentsConfiguration.url(),
            {
                search: search || undefined,
                page:
                    pagination.current_page > 1
                        ? pagination.current_page
                        : undefined,
            },
            {
                replace: true,
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const closeSheet = () => {
        setSheetOpen(false);
        clearEditQuery();
    };

    const openCreate = () => {
        setCurrent(null);
        form.reset();
        form.clearErrors();
        form.setData(initialDocumentTypeForm);
        setSheetOpen(true);
    };

    const openEdit = (documentType: DocumentTypeRow) => {
        setCurrent(documentType);
        form.reset();
        form.clearErrors();
        form.setData(requirementToFormData(documentType));
        setSheetOpen(true);
    };

    const submit = () => {
        if (current) {
            form.put(updateDocumentType.url(current.id), {
                preserveScroll: true,
                onSuccess: closeSheet,
            });

            return;
        }

        form.post(storeDocumentType.url(), {
            preserveScroll: true,
            onSuccess: closeSheet,
        });
    };

    const requestDelete = (documentType: DocumentTypeRow) => {
        setCurrent(documentType);
        setDeleteOpen(true);
    };

    const confirmDelete = () => {
        if (!current) {
            return;
        }

        router.delete(destroyDocumentType.url(current.id), {
            preserveScroll: true,
            onFinish: () => {
                setDeleteOpen(false);
                setCurrent(null);
            },
        });
    };

    const toggleActive = (documentType: DocumentTypeRow) => {
        router.put(
            updateDocumentType.url(documentType.id),
            {
                title: documentType.title,
                is_active: !documentType.is_active,
            },
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <Main>
            <PageHeader
                kicker="Documents"
                title="Document Types"
                description="Define document categories, requirements, and who needs each document."
                right={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => setImportOpen(true)}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Import CSV
                        </Button>
                        {can.create ? (
                            <Button onClick={openCreate}>
                                Add document type
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <SearchBar
                value={list.searchInput}
                onChange={list.onSearchChange}
                placeholder="Search by title…"
            />

            {documentTypes.length === 0 ? (
                <EmptyState
                    title={
                        list.searchInput
                            ? 'No document types found.'
                            : 'No document types yet.'
                    }
                    description={
                        list.searchInput
                            ? 'Try adjusting your search query to find the document type.'
                            : 'Add a document type to organize employee documents and configure requirements.'
                    }
                    action={
                        !list.searchInput && can.create ? (
                            <Button onClick={openCreate}>
                                Add document type
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <>
                    {/* Mobile Record List (Small screens) */}
                    <div className="md:hidden">
                        <MobileRecordList labelledBy="document-types-mobile-heading">
                            {documentTypes.map((documentType) => {
                                const isRequired =
                                    documentType.requirement?.is_required;
                                const appliesTo = documentTypeAppliesToLabel(
                                    documentType.requirement,
                                );
                                const expiryLabel = documentTypeExpiryLabel(
                                    documentType.requirement,
                                );

                                const metaParts: string[] = [];

                                if (isRequired) {
                                    metaParts.push(`Applies to: ${appliesTo}`);
                                } else {
                                    metaParts.push('Optional document');
                                }

                                if (expiryLabel !== '—') {
                                    metaParts.push('Expiry tracked');
                                }

                                const overflowActions: MobileRecordOverflowAction[] =
                                    [];

                                if (can.update) {
                                    overflowActions.push({
                                        key: 'edit',
                                        label: 'Edit',
                                        onSelect: () => openEdit(documentType),
                                    });
                                }

                                if (can.delete) {
                                    overflowActions.push({
                                        key: 'delete',
                                        label: 'Delete',
                                        destructive: true,
                                        onSelect: () =>
                                            requestDelete(documentType),
                                    });
                                }

                                return (
                                    <MobileRecordCard
                                        key={documentType.id}
                                        title={documentType.title}
                                        subtitle={
                                            isRequired ? 'Required' : 'Optional'
                                        }
                                        meta={metaParts}
                                        href={detailHref(documentType.id)}
                                        status={
                                            <Badge
                                                variant={
                                                    documentType.is_active
                                                        ? 'success'
                                                        : 'secondary'
                                                }
                                            >
                                                {documentType.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                        }
                                        overflowActions={overflowActions}
                                        primaryAction={{
                                            label: 'View',
                                            href: detailHref(documentType.id),
                                        }}
                                    />
                                );
                            })}
                        </MobileRecordList>
                    </div>

                    {/* Desktop Data Table */}
                    <div className="hidden md:block">
                        <OrganizationDataTable minWidth="min-w-[960px]" compact>
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Document Type</DataTableHead>
                                    <DataTableHead>Requirement</DataTableHead>
                                    <DataTableHead>Applies To</DataTableHead>
                                    <DataTableHead>Expiry</DataTableHead>
                                    <DataTableHead>Status</DataTableHead>
                                    <DataTableHead className="text-right">
                                        Actions
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {documentTypes.map((documentType) => {
                                    const isRequired =
                                        documentType.requirement?.is_required;
                                    const appliesTo =
                                        documentTypeAppliesToLabel(
                                            documentType.requirement,
                                        );
                                    const viewHref = detailHref(
                                        documentType.id,
                                    );

                                    return (
                                        <TableRow
                                            key={documentType.id}
                                            className={cn(
                                                dataTableBodyRowClass(false),
                                                'cursor-pointer',
                                            )}
                                            onClick={() =>
                                                router.visit(viewHref)
                                            }
                                        >
                                            <TableCell
                                                className={dataTableCellPrimaryClass()}
                                            >
                                                {documentType.title}
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                <Badge
                                                    variant={
                                                        isRequired
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                    className="font-normal"
                                                >
                                                    {documentTypeRequirementStatus(
                                                        documentType.requirement,
                                                    )}
                                                </Badge>
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                <span
                                                    className={cn(
                                                        !isRequired &&
                                                            'text-muted-foreground',
                                                    )}
                                                >
                                                    {appliesTo}
                                                </span>
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                <span
                                                    className={cn(
                                                        documentTypeExpiryLabel(
                                                            documentType.requirement,
                                                        ) === '—' &&
                                                            'text-muted-foreground',
                                                    )}
                                                >
                                                    {documentTypeExpiryLabel(
                                                        documentType.requirement,
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant={
                                                            documentType.is_active
                                                                ? 'success'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {documentType.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                    <Switch
                                                        disabled={!can.update}
                                                        checked={
                                                            documentType.is_active
                                                        }
                                                        onCheckedChange={() =>
                                                            toggleActive(
                                                                documentType,
                                                            )
                                                        }
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                        aria-label={`Toggle ${documentType.title} status`}
                                                    />
                                                </div>
                                            </TableCell>
                                            <TableCell
                                                className={dataTableActionsCellClass()}
                                                onClick={(event) =>
                                                    event.stopPropagation()
                                                }
                                            >
                                                <ListTableCrudActions
                                                    showView
                                                    viewHref={viewHref}
                                                    showEdit={can.update}
                                                    showDelete={can.delete}
                                                    onEdit={() =>
                                                        openEdit(documentType)
                                                    }
                                                    onDelete={() =>
                                                        requestDelete(
                                                            documentType,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>
                </>
            )}

            <Pagination {...list.paginationProps} label="document types" />

            <DocumentTypeFormSheet
                open={sheetOpen}
                onOpenChange={(open) => {
                    if (open) {
                        setSheetOpen(true);

                        return;
                    }

                    closeSheet();
                }}
                current={current}
                form={form}
                canUpdate={can.update}
                departments={departments}
                positions={positions}
                ranks={ranks}
                projects={projects}
                onSubmit={submit}
            />

            <DocumentTypeImportDialog
                open={importOpen}
                onOpenChange={setImportOpen}
            />

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete document type?"
                description="This action cannot be undone."
                confirmText="Delete"
                onConfirm={confirmDelete}
            />
        </Main>
    );
}
