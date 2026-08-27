import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canViewDocumentsModuleSection,
    documentsModuleIndexPath,
    documentsLibraryQuery,
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
                page: 2,
            }),
            {
                search: 'visa',
                expiry: 'expired',
                requirement_status: 'missing',
                department_id: '12',
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

    it('shows templates for document types or platform access', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections([
                'settings.master-data.document-types.view',
            ]),
            ['templates'],
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
