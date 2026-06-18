import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DocumentsIndexDocumentsTable } from '@/features/organization/documents/index/documents-index-documents-table';
import { DocumentsIndexFolderGrid } from '@/features/organization/documents/index/documents-index-folder-grid';
import { DocumentsIndexSectionHeading } from '@/features/organization/documents/index/documents-index-section-heading';
import type { DocumentsIndexSearchMode } from '@/features/organization/documents/index/use-documents-index-search-mode';
import type {
    ComplianceDocumentItem,
    EmployeeFolder,
    PaginatedComplianceDocuments,
} from '@/features/organization/documents/shared/types';

type FolderGridProps = {
    employees: EmployeeFolder[];
    canDownload: boolean;
    isSearching: boolean;
    isFolderSelected: (id: number) => boolean;
    allFoldersSelected: boolean;
    foldersPartiallySelected: boolean;
    onToggleFolder: (id: number) => void;
    onToggleAllFolders: () => void;
    onClearFolderSelection: () => void;
    onBulkDownload: () => void;
    isBulkDownloading: boolean;
    selectedFolderCount: number;
};

type DocumentManagementProps = {
    canDownload: boolean;
    canUpload: boolean;
    canDelete: boolean;
    buildViewHref: (doc: ComplianceDocumentItem) => string;
    onEdit: (doc: ComplianceDocumentItem) => void;
    onReplace: (doc: ComplianceDocumentItem) => void;
    onDelete: (doc: ComplianceDocumentItem) => void;
};

type Props = {
    mode: DocumentsIndexSearchMode;
    searchQuery: string;
    employees: EmployeeFolder[];
    searchDocuments: PaginatedComplianceDocuments;
    onPageChange: (page: number) => void;
    folderGridProps: FolderGridProps;
} & DocumentManagementProps;

function EmployeesSection({
    employees,
    folderGridProps,
}: {
    employees: EmployeeFolder[];
    folderGridProps: FolderGridProps;
}) {
    if (employees.length === 0) {
        return null;
    }

    return (
        <section className="space-y-4" aria-labelledby="documents-index-employees-heading">
            <DocumentsIndexSectionHeading label="Employees" count={employees.length} />
            <DocumentsIndexFolderGrid employees={employees} {...folderGridProps} />
        </section>
    );
}

function DocumentsSection({
    searchDocuments,
    onPageChange,
    documentManagementProps,
}: {
    searchDocuments: PaginatedComplianceDocuments;
    onPageChange: (page: number) => void;
    documentManagementProps: DocumentManagementProps;
}) {
    const count = searchDocuments.total;

    if (count === 0) {
        return null;
    }

    return (
        <section className="space-y-4" aria-labelledby="documents-index-documents-heading">
            <DocumentsIndexSectionHeading label="Documents" count={count} />
            <DocumentsIndexDocumentsTable
                documents={searchDocuments}
                onPageChange={onPageChange}
                {...documentManagementProps}
            />
        </section>
    );
}

export function DocumentsIndexSearchResults({
    mode,
    searchQuery,
    employees,
    searchDocuments,
    onPageChange,
    folderGridProps,
    canDownload,
    canUpload,
    canDelete,
    buildViewHref,
    onEdit,
    onReplace,
    onDelete,
}: Props) {
    const documentManagementProps = {
        canDownload,
        canUpload,
        canDelete,
        buildViewHref,
        onEdit,
        onReplace,
        onDelete,
    };
    const employeeCount = employees.length;
    const documentCount = searchDocuments.total;

    if (mode === 'documents-only') {
        return (
            <DocumentsSection
                searchDocuments={searchDocuments}
                onPageChange={onPageChange}
                documentManagementProps={documentManagementProps}
            />
        );
    }

    if (mode === 'employees-only') {
        return <EmployeesSection employees={employees} folderGridProps={folderGridProps} />;
    }

    if (mode !== 'tabbed') {
        return null;
    }

    return (
        <Tabs key={`${searchQuery}-${mode}`} defaultValue="all">
            <TabsList className="mb-2 h-10 w-full justify-start gap-1 sm:w-auto">
                <TabsTrigger value="all" className="px-4">
                    All
                </TabsTrigger>
                <TabsTrigger value="employees" className="px-4">
                    Employees ({employeeCount})
                </TabsTrigger>
                <TabsTrigger value="documents" className="px-4">
                    Documents ({documentCount})
                </TabsTrigger>
            </TabsList>

            <TabsContent value="all" className="mt-6 space-y-10">
                <EmployeesSection employees={employees} folderGridProps={folderGridProps} />
                <DocumentsSection
                    searchDocuments={searchDocuments}
                    onPageChange={onPageChange}
                    documentManagementProps={documentManagementProps}
                />
            </TabsContent>

            <TabsContent value="employees" className="mt-6">
                <EmployeesSection employees={employees} folderGridProps={folderGridProps} />
            </TabsContent>

            <TabsContent value="documents" className="mt-6">
                <DocumentsSection
                    searchDocuments={searchDocuments}
                    onPageChange={onPageChange}
                    documentManagementProps={documentManagementProps}
                />
            </TabsContent>
        </Tabs>
    );
}
