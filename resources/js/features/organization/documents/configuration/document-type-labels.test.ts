import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    documentTypeAppliesToLabel,
    documentTypeExpiryLabel,
    documentTypeRequirementStatus,
    requirementToFormData,
} from './types.ts';
import type { DocumentRequirementPayload, DocumentTypeRow } from './types.ts';

describe('document type helpers', () => {
    it('formats expiry label correctly', () => {
        assert.equal(documentTypeExpiryLabel(undefined), '—');
        assert.equal(
            documentTypeExpiryLabel({
                is_required: false,
                required_for_all: false,
                department_ids: [],
                position_ids: [],
                rank_ids: [],
                project_ids: [],
                require_issue_date: false,
                require_expiry_date: true,
                require_document_number: false,
                label: 'Optional',
            }),
            '—',
        );
        assert.equal(
            documentTypeExpiryLabel({
                is_required: true,
                required_for_all: true,
                department_ids: [],
                position_ids: [],
                rank_ids: [],
                project_ids: [],
                require_issue_date: false,
                require_expiry_date: false,
                require_document_number: false,
                label: 'All employees',
            }),
            '—',
        );
        assert.equal(
            documentTypeExpiryLabel({
                is_required: true,
                required_for_all: true,
                department_ids: [],
                position_ids: [],
                rank_ids: [],
                project_ids: [],
                require_issue_date: false,
                require_expiry_date: true,
                require_document_number: false,
                label: 'All employees',
            }),
            'Tracked',
        );
    });

    it('returns requirement status label', () => {
        assert.equal(documentTypeRequirementStatus(undefined), 'Optional');
        assert.equal(
            documentTypeRequirementStatus({
                is_required: false,
                required_for_all: false,
                department_ids: [],
                position_ids: [],
                rank_ids: [],
                project_ids: [],
                require_issue_date: false,
                require_expiry_date: false,
                require_document_number: false,
                label: 'Optional',
            }),
            'Optional',
        );
        assert.equal(
            documentTypeRequirementStatus({
                is_required: true,
                required_for_all: false,
                department_ids: [1],
                position_ids: [],
                rank_ids: [],
                project_ids: [],
                require_issue_date: false,
                require_expiry_date: false,
                require_document_number: false,
                label: 'Engineering',
            }),
            'Required',
        );
    });

    it('formats applies to label for optional and required scopes', () => {
        assert.equal(documentTypeAppliesToLabel(undefined), '—');

        const optionalReq: DocumentRequirementPayload = {
            is_required: false,
            required_for_all: false,
            department_ids: [],
            position_ids: [],
            rank_ids: [],
            project_ids: [],
            require_issue_date: false,
            require_expiry_date: false,
            require_document_number: false,
            label: 'Optional',
        };
        assert.equal(documentTypeAppliesToLabel(optionalReq), '—');

        const allReq: DocumentRequirementPayload = {
            is_required: true,
            required_for_all: true,
            department_ids: [],
            position_ids: [],
            rank_ids: [],
            project_ids: [],
            require_issue_date: false,
            require_expiry_date: false,
            require_document_number: false,
            label: 'All employees',
        };
        assert.equal(documentTypeAppliesToLabel(allReq), 'All employees');

        const scopedReq: DocumentRequirementPayload = {
            is_required: true,
            required_for_all: false,
            department_ids: [1],
            position_ids: [2],
            rank_ids: [],
            project_ids: [],
            require_issue_date: false,
            require_expiry_date: false,
            require_document_number: false,
            label: 'Engineering · Captain',
        };
        assert.equal(
            documentTypeAppliesToLabel(scopedReq),
            'Engineering · Captain',
        );

        const fallbackScopedReq: DocumentRequirementPayload = {
            is_required: true,
            required_for_all: false,
            department_ids: [],
            position_ids: [],
            rank_ids: [],
            project_ids: [],
            require_issue_date: false,
            require_expiry_date: false,
            require_document_number: false,
            label: 'Optional',
        };
        assert.equal(
            documentTypeAppliesToLabel(fallbackScopedReq),
            'Selected groups',
        );
    });

    it('converts row data to form data with fallbacks', () => {
        const row: DocumentTypeRow = {
            id: 10,
            title: 'Passport',
            is_active: true,
            requirement: {
                is_required: true,
                required_for_all: false,
                department_ids: [1, 2],
                position_ids: [5],
                rank_ids: [],
                project_ids: [8],
                require_issue_date: true,
                require_expiry_date: true,
                require_document_number: false,
                label: '2 departments · Captain',
            },
        };

        const formData = requirementToFormData(row);
        assert.equal(formData.title, 'Passport');
        assert.equal(formData.is_active, true);
        assert.equal(formData.is_required, true);
        assert.equal(formData.required_for_all, false);
        assert.deepEqual(formData.department_ids, [1, 2]);
        assert.deepEqual(formData.position_ids, [5]);
        assert.deepEqual(formData.project_ids, [8]);
        assert.equal(formData.require_issue_date, true);
        assert.equal(formData.require_expiry_date, true);
        assert.equal(formData.require_document_number, false);
    });
});
