import type { ComplianceDocumentItem } from '../shared/types';

export type DocumentComplianceMobileCardModel = {
    title: string;
    employeeLine: string;
    documentNumber: string | null;
    expiryLine: string | null;
    expiryStatus: string | null;
    attention: string | null;
    showDownload: boolean;
    showEdit: boolean;
    showReplace: boolean;
    showDelete: boolean;
};

export function documentComplianceMobileCardModel(
    doc: ComplianceDocumentItem,
    can: { download: boolean; upload: boolean; delete: boolean },
): DocumentComplianceMobileCardModel {
    const employeeLine = [doc.employee_name, doc.employee_no]
        .map((value) => value?.trim())
        .filter((value): value is string => Boolean(value))
        .join(' · ');

    const expiryStatus = doc.expiry_status ?? null;
    const attention =
        expiryStatus === 'expired'
            ? 'Expired'
            : expiryStatus === 'expiring_7' || expiryStatus === 'expiring_15'
              ? doc.expiry_label
              : null;

    return {
        title:
            doc.document_type_label || doc.document_type || doc.document_name,
        employeeLine,
        documentNumber: doc.document_number?.trim() || null,
        expiryLine: doc.expiry_date ? `Expires ${doc.expiry_date}` : null,
        expiryStatus,
        attention,
        showDownload: can.download,
        showEdit: can.upload,
        showReplace: can.upload,
        showDelete: can.delete,
    };
}
