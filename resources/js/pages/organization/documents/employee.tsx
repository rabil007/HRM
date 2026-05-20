import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Eye, FileText } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { TableRowActions } from '@/components/table-row-actions';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { DocumentPreviewDialog } from '@/features/organization/employee-documents/document-preview-dialog';
import { formatDisplayDate } from '@/lib/format-date';

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

export default function EmployeeDocumentsBrowse({ employee, documents }: Props) {
    const [previewDoc, setPreviewDoc] = useState<DocumentItem | null>(null);

    return (
        <Main>
            <Head title={`Documents — ${employee.name}`} />

            <div className="mb-4">
                <Button variant="ghost" size="sm" className="gap-2 rounded-lg" asChild>
                    <Link href="/organization/documents">
                        <ArrowLeft className="h-4 w-4" />
                        Documents
                    </Link>
                </Button>
            </div>

            <PageHeader
                title={employee.name}
                description={`Employee no. ${employee.employee_no} · ${documents.length} file${documents.length === 1 ? '' : 's'}`}
                right={
                    <Button variant="outline" size="sm" className="rounded-lg" asChild>
                        <Link href={`/organization/employees/${employee.id}#documents`}>
                            Manage on profile
                        </Link>
                    </Button>
                }
            />

            <OrganizationDataTable minWidth="min-w-[720px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead>Name</DataTableHead>
                        <DataTableHead>Type</DataTableHead>
                        <DataTableHead>Uploaded</DataTableHead>
                        <DataTableHead className="text-right">Actions</DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {documents.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={4} className="py-16 text-center">
                                <div className="flex flex-col items-center gap-3 text-muted-foreground">
                                    <FileText className="h-8 w-8 opacity-30" />
                                    <p className="text-sm">No documents for this employee.</p>
                                </div>
                            </TableCell>
                        </TableRow>
                    ) : (
                        documents.map((doc) => (
                            <TableRow key={doc.id} className={dataTableBodyRowClass()}>
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    {doc.document_name}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {doc.document_type}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
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
                        ))
                    )}
                </TableBody>
            </OrganizationDataTable>

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
