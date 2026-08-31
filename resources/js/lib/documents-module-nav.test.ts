import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canViewDocumentsModuleSection,
    documentsModuleIndexPath,
    documentsLibraryQuery,
    documentsOverviewTypeViewQuery,
    documentsModuleSectionFromUrl,
    documentsShowBackFromSection,
    isDocumentsModuleNavUrlActive,
    visibleDocumentsModuleSections,
} from './documents-module-nav.ts';

describe('documents module URL mapping', () => {
    it('maps overview and library independently', () => {
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents'),
            'overview',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents?search=pass',
            ),
            'overview',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/library'),
            'library',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/employees/12',
            ),
            'library',
        );
    });

    it('maps generate requests and activity including legacy bulk urls', () => {
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/generate'),
            'generate',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/bulk'),
            'generate',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/bulk?view=signatures',
            ),
            'requests',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/requests'),
            'requests',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/bulk?view=history',
            ),
            'activity',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/activity'),
            'activity',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/templates'),
            'templates',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/configuration',
            ),
            'configuration',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/configuration?edit=12',
            ),
            'configuration',
        );
    });

    it('keeps saved views on the active overview or library path', () => {
        assert.equal(
            documentsModuleIndexPath('overview'),
            '/organization/documents',
        );
        assert.equal(
            documentsModuleIndexPath('library'),
            '/organization/documents/library',
        );
        assert.equal(documentsShowBackFromSection('overview'), 'index');
        assert.equal(documentsShowBackFromSection('library'), 'library');
    });

    it('builds library query state from supported filters only', () => {
        assert.deepEqual(
            documentsLibraryQuery({
                search: ' visa ',
                expiry: 'expired',
                requirement_status: 'missing',
                department_id: '12',
                document_type_id: 9,
                page: 2,
            }),
            {
                search: 'visa',
                expiry: 'expired',
                requirement_status: 'missing',
                department_id: '12',
                document_type_id: '9',
                page: '2',
            },
        );
        assert.deepEqual(
            documentsLibraryQuery({
                expiry: 'all',
                search: '  ',
                page: 1,
            }),
            {},
        );
        assert.deepEqual(
            documentsOverviewTypeViewQuery({
                document_type_id: 12,
                missing: 7,
                expired: 1,
            }),
            {
                requirement_status: 'missing',
                document_type_id: '12',
            },
        );
        assert.deepEqual(
            documentsOverviewTypeViewQuery({
                document_type_id: 12,
                missing: 0,
                expired: 4,
            }),
            {
                expiry: 'expired',
                document_type_id: '12',
            },
        );
        assert.deepEqual(
            documentsOverviewTypeViewQuery({
                document_type_id: 12,
                missing: 0,
                expired: 0,
            }),
            {
                requirement_status: 'expiring',
                document_type_id: '12',
            },
        );
    });

    it('keeps overview inactive on other documents module urls', () => {
        assert.equal(
            isDocumentsModuleNavUrlActive(
                '/organization/documents/library',
                '/organization/documents',
            ),
            false,
        );
        assert.equal(
            isDocumentsModuleNavUrlActive(
                '/organization/documents/bulk?view=signatures',
                '/organization/documents/requests',
            ),
            true,
        );
        assert.equal(
            isDocumentsModuleNavUrlActive(
                '/organization/documents/bulk',
                '/organization/documents/generate',
            ),
            true,
        );
        assert.equal(
            isDocumentsModuleNavUrlActive(
                '/organization/contracts',
                '/organization/documents',
            ),
            false,
        );
        assert.equal(
            isDocumentsModuleNavUrlActive(
                '/organization/documents',
                '/organization/contracts',
            ),
            null,
        );
    });
});

describe('documents module visibility', () => {
    it('shows overview and library only with documents.view', () => {
        assert.deepEqual(visibleDocumentsModuleSections(['documents.view']), [
            'overview',
            'library',
        ]);
        assert.equal(
            canViewDocumentsModuleSection('generate', ['documents.view']),
            false,
        );
    });

    it('shows generate requests and activity with bulk_documents.view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['bulk_documents.view']),
            ['generate', 'requests', 'templates', 'activity'],
        );
    });

    it('shows templates for document types, custom templates view, or platform access', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['documents.templates.view']),
            ['templates'],
        );
        assert.deepEqual(
            visibleDocumentsModuleSections([
                'settings.master-data.document-types.view',
            ]),
            ['templates', 'configuration'],
        );
        assert.equal(
            canViewDocumentsModuleSection('configuration', ['documents.view']),
            false,
        );
        assert.deepEqual(visibleDocumentsModuleSections([], true), [
            'templates',
        ]);
    });

    it('does not treat bulk generate as bulk view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['bulk_documents.generate']),
            [],
        );
    });
});
