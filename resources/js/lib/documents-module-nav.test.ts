import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canViewDocumentsModuleSection,
    DOCUMENTS_MODULE_LABELS,
    documentsModuleIndexPath,
    documentsLibraryQuery,
    documentsOverviewTypeViewQuery,
    documentsModuleSectionFromUrl,
    documentsShowBackFromSection,
    isDocumentsModuleNavUrlActive,
    visibleDocumentsModuleSections,
} from './documents-module-nav.ts';

describe('documents module URL mapping', () => {
    it('labels the document types section for users', () => {
        assert.equal(DOCUMENTS_MODULE_LABELS.configuration, 'Document Types');
        assert.equal(
            DOCUMENTS_MODULE_LABELS['notification-routing'],
            'Notification Routing',
        );
    });

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

    it('maps activity urls into the Generate & Track section', () => {
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
            'generate',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/requests'),
            'requests',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/generate?view=activity',
            ),
            'generate',
        );
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/bulk?view=history',
            ),
            'generate',
        );
        assert.equal(
            documentsModuleSectionFromUrl('/organization/documents/activity'),
            'generate',
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
        assert.equal(
            documentsModuleSectionFromUrl(
                '/organization/documents/configuration/notification-routing',
            ),
            'notification-routing',
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
                '/organization/documents/generate',
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

    it('shows templates and Generate & Track with bulk_documents.view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['bulk_documents.view']),
            ['templates', 'generate'],
        );
    });

    it('shows requests only with current document request permissions', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['documents.requests.view']),
            ['requests'],
        );
        assert.deepEqual(
            visibleDocumentsModuleSections([
                'documents.recipient-requests.respond',
            ]),
            ['requests'],
        );
    });

    it('shows templates for custom templates view or bulk documents view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['documents.templates.view']),
            ['templates'],
        );
        assert.deepEqual(
            visibleDocumentsModuleSections(['bulk_documents.view']),
            ['templates', 'generate'],
        );
        assert.deepEqual(visibleDocumentsModuleSections([]), []);
    });

    it('shows only configuration for document-types-only users, not templates', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections([
                'settings.master-data.document-types.view',
            ]),
            ['configuration'],
        );
        assert.equal(
            canViewDocumentsModuleSection('templates', [
                'settings.master-data.document-types.view',
            ]),
            false,
        );
        assert.equal(
            canViewDocumentsModuleSection('configuration', [
                'settings.master-data.document-types.view',
            ]),
            true,
        );
        assert.equal(
            canViewDocumentsModuleSection('configuration', ['documents.view']),
            false,
        );
    });

    it('shows notification routing only with notification-routing.view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections([
                'documents.notification-routing.view',
            ]),
            ['notification-routing'],
        );
        assert.equal(
            canViewDocumentsModuleSection('notification-routing', [
                'settings.master-data.document-types.view',
            ]),
            false,
        );
    });

    it('does not treat bulk generate as bulk view', () => {
        assert.deepEqual(
            visibleDocumentsModuleSections(['bulk_documents.generate']),
            [],
        );
    });
});
