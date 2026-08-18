import { MobileRecordCard } from '@/components/mobile-record-list';
import type { MobileRecordOverflowAction } from '@/components/mobile-record-list';
import { Checkbox } from '@/components/ui/checkbox';
import { documentComplianceMobileCardModel } from '@/features/organization/documents/lib/document-compliance-mobile-card';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import type { ComplianceDocumentItem } from '@/features/organization/documents/shared/types';
import { formatDisplayDate } from '@/lib/format-date';
import documentRoutes from '@/routes/organization/documents';

export function DocumentComplianceMobileCard({
    doc,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    selected = false,
    onSelectedChange,
    selectionMode = false,
    onEdit,
    onReplace,
    onDelete,
}: {
    doc: ComplianceDocumentItem;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
    selectionMode?: boolean;
    onEdit?: () => void;
    onReplace?: () => void;
    onDelete?: () => void;
}) {
    const model = documentComplianceMobileCardModel(doc, {
        download: canDownload,
        upload: canUpload,
        delete: canDelete,
    });
    const overflowActions: MobileRecordOverflowAction[] = [];

    if (model.showDownload) {
        overflowActions.push({
            key: 'download',
            label: 'Download',
            href: documentRoutes.files.download.url({ document: doc.id }),
        });
    }

    if (model.showEdit && onEdit) {
        overflowActions.push({
            key: 'edit',
            label: 'Edit details',
            onSelect: onEdit,
        });
    }

    if (model.showReplace && onReplace) {
        overflowActions.push({
            key: 'replace',
            label: 'Replace file',
            onSelect: onReplace,
        });
    }

    if (model.showDelete && onDelete) {
        overflowActions.push({
            key: 'delete',
            label: 'Delete',
            destructive: true,
            onSelect: onDelete,
        });
    }

    return (
        <MobileRecordCard
            title={model.title}
            subtitle={model.employeeLine}
            meta={[
                model.documentNumber,
                doc.expiry_date
                    ? `Expires ${formatDisplayDate(doc.expiry_date)}`
                    : null,
            ]}
            status={
                <DocumentExpiryBadge
                    status={model.expiryStatus}
                    className="text-[10px]"
                />
            }
            attention={model.attention}
            href={viewHref}
            leading={
                selectionMode ? (
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) =>
                            onSelectedChange?.(value === true)
                        }
                        aria-label={`Select ${model.title}`}
                    />
                ) : null
            }
            overflowActions={overflowActions}
        />
    );
}
