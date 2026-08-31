import { Head } from '@inertiajs/react';
import { DocumentTypesContent } from '@/features/organization/documents/configuration/document-types-content';
import type {
    DepartmentOption,
    DocumentTypeRow,
    PositionOption,
    ProjectOption,
    RankOption,
} from '@/features/organization/documents/configuration/types';
import type { PaginationMeta } from '@/types/pagination';

export default function DocumentTypes({
    document_types,
    pagination,
    search = '',
    departments = [],
    positions = [],
    ranks = [],
    projects = [],
    open_document_type = null,
}: {
    document_types: DocumentTypeRow[];
    pagination: PaginationMeta;
    search?: string;
    departments?: DepartmentOption[];
    positions?: PositionOption[];
    ranks?: RankOption[];
    projects?: ProjectOption[];
    open_document_type?: DocumentTypeRow | null;
}) {
    return (
        <>
            <Head title="Document Types" />
            <DocumentTypesContent
                documentTypes={document_types}
                pagination={pagination}
                search={search}
                departments={departments}
                positions={positions}
                ranks={ranks}
                projects={projects}
                openDocumentType={open_document_type}
            />
        </>
    );
}
