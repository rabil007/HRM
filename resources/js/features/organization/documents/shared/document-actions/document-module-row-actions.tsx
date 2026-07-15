import type { ReactElement } from 'react';
import { DocumentListRowActions } from '@/features/organization/documents/shared/document-actions/document-list-row-actions';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';

type DocumentModuleRowActionsProps = {
    doc: DocumentBrowseItem;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    onReplace?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    className?: string;
};

export function DocumentModuleRowActions({
    doc,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    onReplace,
    onEdit,
    onDelete,
    className,
}: DocumentModuleRowActionsProps): ReactElement {
    return (
        <div className="inline-flex items-center justify-end gap-0.5">
            <DocumentListRowActions
                documentId={doc.id}
                fileUrl={doc.file_url}
                viewHref={viewHref}
                showDownload={canDownload}
                showReplace={canUpload}
                onReplace={onReplace}
                showEdit={canUpload}
                onEdit={onEdit}
                showDelete={canDelete}
                onDelete={onDelete}
                className={className}
            />
        </div>
    );
}
