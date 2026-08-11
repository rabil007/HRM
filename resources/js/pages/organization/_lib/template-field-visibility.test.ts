import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from './template-record-defaults.ts';
import {
    collectMissingRequiredTemplateFields,
    getTemplateRequiredFieldKeys,
} from './template-field-visibility.ts';

describe('replace document required-field validation', () => {
    it('requires document_type_id by default when no template is assigned', () => {
        const required = getTemplateRequiredFieldKeys(
            null,
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
        );

        assert.ok(required.has('document_type_id'));
    });

    it('flags replace metadata without document_type_id as incomplete', () => {
        const missing = collectMissingRequiredTemplateFields(
            {
                document_number: '23442',
                issue_date: '2026-08-01',
                expiry_date: '2026-08-31',
            },
            null,
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
        );

        assert.deepEqual(missing, ['document_type_id']);
    });

    it('accepts replace metadata when existing document_type_id is supplied', () => {
        const missing = collectMissingRequiredTemplateFields(
            {
                document_type_id: 12,
                document_number: '23442',
                issue_date: '2026-08-01',
                expiry_date: '2026-08-31',
            },
            null,
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
        );

        assert.deepEqual(missing, []);
    });
});

describe('replace training required-field validation', () => {
    it('flags replace metadata without course_id as incomplete', () => {
        const missing = collectMissingRequiredTemplateFields(
            {
                issue_date: '2026-08-01',
                institute_center: 'Academy',
            },
            null,
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
        );

        assert.ok(missing.includes('course_id'));
    });

    it('accepts replace metadata when existing course_id is supplied', () => {
        const missing = collectMissingRequiredTemplateFields(
            {
                course_id: 4,
                issue_date: '2026-08-01',
                institute_center: 'Academy',
            },
            null,
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
        );

        assert.deepEqual(missing, []);
    });
});
