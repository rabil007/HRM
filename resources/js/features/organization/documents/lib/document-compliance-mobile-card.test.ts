import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { ComplianceDocumentItem } from '../shared/types.ts';
import { documentComplianceMobileCardModel } from './document-compliance-mobile-card.ts';

function document(
    overrides: Partial<ComplianceDocumentItem> = {},
): ComplianceDocumentItem {
    return {
        id: 44,
        employee_id: 12,
        employee_name: 'Mohammed Rabil',
        employee_no: 'EMP-0012',
        document_name: 'passport.pdf',
        document_type: 'Passport',
        document_type_label: 'Passport',
        title: 'Passport',
        type: 'Passport',
        document_type_id: 1,
        file_url: '/files/passport.pdf',
        file_path: 'passport.pdf',
        original_filename: 'passport.pdf',
        uploaded_at: '2026-01-01',
        uploaded_by: 'HR',
        mime_type: 'application/pdf',
        can_preview: true,
        status: 'valid',
        expiry_date: '2027-10-12',
        issue_date: '2022-10-12',
        document_number: 'P123456',
        notes: null,
        current_version: 1,
        size_bytes: 1200,
        expiry_status: 'valid',
        remaining_days: 400,
        expiry_label: 'Valid',
        versions: [],
        ...overrides,
    };
}

describe('documentComplianceMobileCardModel', () => {
    it('prioritizes type, employee, number, and expiry', () => {
        const model = documentComplianceMobileCardModel(document(), {
            download: true,
            upload: false,
            delete: false,
        });

        assert.equal(model.title, 'Passport');
        assert.equal(model.employeeLine, 'Mohammed Rabil · EMP-0012');
        assert.equal(model.documentNumber, 'P123456');
        assert.equal(model.expiryLine, 'Expires 2027-10-12');
        assert.equal(model.expiryStatus, 'valid');
        assert.equal(model.attention, null);
        assert.equal(
            JSON.stringify(model).includes('/files/passport.pdf'),
            false,
        );
    });

    it('surfaces expiry and compliance warnings', () => {
        const expired = documentComplianceMobileCardModel(
            document({
                expiry_status: 'expired',
                expiry_label: 'Expired',
            }),
            { download: false, upload: false, delete: false },
        );
        const expiring = documentComplianceMobileCardModel(
            document({
                expiry_status: 'expiring_7',
                expiry_label: 'Expires in 5 days',
            }),
            { download: false, upload: false, delete: false },
        );

        assert.equal(expired.attention, 'Expired');
        assert.equal(expiring.attention, 'Expires in 5 days');
    });

    it('gates authorized actions only', () => {
        const viewOnly = documentComplianceMobileCardModel(document(), {
            download: false,
            upload: false,
            delete: false,
        });
        const privileged = documentComplianceMobileCardModel(document(), {
            download: true,
            upload: true,
            delete: true,
        });

        assert.equal(viewOnly.showDownload, false);
        assert.equal(viewOnly.showEdit, false);
        assert.equal(viewOnly.showReplace, false);
        assert.equal(viewOnly.showDelete, false);
        assert.equal(privileged.showDownload, true);
        assert.equal(privileged.showEdit, true);
        assert.equal(privileged.showReplace, true);
        assert.equal(privileged.showDelete, true);
    });
});
