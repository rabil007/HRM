import { Head, Link } from '@inertiajs/react';
import { ExternalLink, Eye, FolderOpen } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { TableRowActions } from '@/components/table-row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { DocumentFileIcon } from '@/features/organization/documents/document-file-icon';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

type EmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};

type DocumentItem = {
    id: number;
    document_name: string;
    document_type: string;
    file_url: string;
    uploaded_at: string | null;
    mime_type: string | null;
    can_preview: boolean;
    status: string | null;
};

type Props = {
    employee: EmployeeSummary;
    documents: DocumentItem[];
};

function matchesFileSearch(doc: DocumentItem, query: string): boolean {
    const haystack = [
        doc.document_name,
        doc.document_type,
        formatDisplayDate(doc.uploaded_at),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(query);
}

export default function EmployeeDocumentsBrowse({ employee, documents }: Props) {
    const [previewDoc, setPreviewDoc] = useState<DocumentItem | null>(null);
    const [fileSearch, setFileSearch] = useState('');

    const filteredDocuments = useMemo(() => {
        const query = fileSearch.trim().toLowerCase();

        if (query === '') {
            return documents;
        }

        return documents.filter((doc) => matchesFileSearch(doc, query));
    }, [documents, fileSearch]);

    return (
        <Main>
            <Head title={`Documents — ${employee.name}`} />

            <DocumentsBreadcrumbs
                items={[
                    { title: 'Documents', href: '/organization/documents' },
                    { title: employee.name },
                ]}
            />

            {documents.length > 0 ? (
                <SearchBar
                    className="mb-6"
                    placeholder="Search files by name, type, or date…"
                    value={fileSearch}
                    onChange={setFileSearch}
                />
            ) : null}

            {documents.length === 0 ? (
                <EmptyState
                    title="No documents in this folder."
                    description="Upload files from the employee profile to see them here."
                    action={
                        <Button variant="outline" size="sm" className="rounded-lg" asChild>
                            <Link href={`/organization/employees/${employee.id}#documents`}>
                                <FolderOpen className="mr-2 h-4 w-4" />
                                Go to employee profile
                            </Link>
                        </Button>
                    }
                />
            ) : filteredDocuments.length === 0 ? (
                <EmptyState
                    title="No files match your search."
                    description="Try a different file name, document type, or upload date."
                />
            ) : (
                <OrganizationDataTable minWidth="min-w-[640px]">
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="min-w-[220px]">File</DataTableHead>
                            <DataTableHead className="hidden sm:table-cell">Type</DataTableHead>
                            <DataTableHead className="hidden md:table-cell">Uploaded</DataTableHead>
                            <DataTableHead className="text-right">Actions</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {filteredDocuments.map((doc) => (
                            <TableRow key={doc.id} className={dataTableBodyRowClass(false)}>
                                <TableCell className="px-4 py-4 align-middle">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/5 bg-muted/30">
                                            <DocumentFileIcon
                                                mimeType={doc.mime_type}
                                                fileName={doc.document_name}
                                                className="h-5 w-5"
                                            />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-foreground">
                                                {doc.document_name}
                                            </p>
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground sm:hidden">
                                                {doc.document_type}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground md:hidden">
                                                {formatDisplayDate(doc.uploaded_at)}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                                    <Badge variant="outline" className="max-w-[12rem] truncate font-normal">
                                        {doc.document_type}
                                    </Badge>
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'hidden whitespace-nowrap md:table-cell',
                                    )}
                                >
                                    {formatDisplayDate(doc.uploaded_at)}
                                </TableCell>
                                <TableCell className={dataTableActionsCellClass()}>
                                    <TableRowActions
                                        actions={[
                                            {
                                                label: 'Preview',
                                                icon: Eye,
                                                onClick: () => setPreviewDoc(doc),
                                                hidden: !doc.can_preview,
                                            },
                                            {
                                                label: 'Open file',
                                                icon: ExternalLink,
                                                href: doc.file_url,
                                                target: '_blank',
                                                rel: 'noreferrer',
                                            },
                                        ]}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            )}

            <DocumentPreviewDialog
                document={
                    previewDoc
                        ? {
                              title: previewDoc.document_name,
                              document_type_label: previewDoc.document_type,
                              file_url: previewDoc.file_url,
                              mime_type: previewDoc.mime_type,
                              can_preview: previewDoc.can_preview,
                          }
                        : null
                }
                onOpenChange={(open) => !open && setPreviewDoc(null)}
            />
        </Main>
    );
}
