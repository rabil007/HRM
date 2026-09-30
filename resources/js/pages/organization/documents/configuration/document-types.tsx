import { Head } from '@inertiajs/react';
import type { DocumentAiSettingsProps } from '@/features/organization/documents/configuration/document-ai-settings-card';
import { documentTypeSheetKey } from '@/features/organization/documents/configuration/document-type-sheet-key';
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
    document_ai_settings,
}: {
    document_types: DocumentTypeRow[];
    pagination: PaginationMeta;
    search?: string;
    departments?: DepartmentOption[];
    positions?: PositionOption[];
    ranks?: RankOption[];
    projects?: ProjectOption[];
    open_document_type?: DocumentTypeRow | null;
    document_ai_settings: DocumentAiSettingsProps;
}) {
    return (
        <>
            <Head title="Document Types" />
            <DocumentTypesContent
                key={documentTypeSheetKey(open_document_type?.id)}
                documentTypes={document_types}
                pagination={pagination}
                search={search}
                departments={departments}
                positions={positions}
                ranks={ranks}
                projects={projects}
                openDocumentType={open_document_type}
                documentAiSettings={document_ai_settings}
            />
        </>
    );
}
