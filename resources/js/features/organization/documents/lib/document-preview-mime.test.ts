import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    documentPreviewIsAvailable,
    normalizeDocumentPreviewMimeType,
    resolveDocumentPreviewMimeType,
    resolveDocumentPreviewRenderMode,
} from './document-preview-mime.ts';

const productionDocument7325 = {
    title: 'HSE',
    mime_type: 'application/pdf',
    can_preview: true,
    size_bytes: 172712,
    original_filename: 'HSE INDUCTION-ALY ADLY-13.07.2028.pdf',
    file_url:
        'https://hrm.overseas-ms.com/organization/documents/files/7325/preview',
};

describe('normalizeDocumentPreviewMimeType', () => {
    it('normalizes charset suffixes and casing', () => {
        assert.equal(
            normalizeDocumentPreviewMimeType('Application/PDF; charset=binary'),
            'application/pdf',
        );
    });
});

describe('resolveDocumentPreviewRenderMode', () => {
    it('selects pdf iframe mode for confirmed production document #7325 values', () => {
        assert.equal(
            resolveDocumentPreviewRenderMode(productionDocument7325),
            'pdf',
        );
        assert.equal(documentPreviewIsAvailable(productionDocument7325), true);
    });

    it('does not treat can_preview alone as sufficient without a supported mime', () => {
        assert.equal(
            resolveDocumentPreviewRenderMode({
                can_preview: true,
                file_url: '/organization/documents/files/99/preview',
            }),
            'unsupported',
        );
    });

    it('infers pdf from original filename when stored mime is missing', () => {
        assert.equal(
            resolveDocumentPreviewRenderMode({
                can_preview: true,
                file_url: '/organization/documents/files/7325/preview',
                original_filename: productionDocument7325.original_filename,
            }),
            'pdf',
        );
    });

    it('selects image mode for png documents', () => {
        assert.equal(
            resolveDocumentPreviewRenderMode({
                can_preview: true,
                mime_type: 'image/png',
                file_url: '/organization/documents/files/100/preview',
            }),
            'image',
        );
    });

    it('honours can_preview false even for pdf mime types', () => {
        assert.equal(
            resolveDocumentPreviewRenderMode({
                can_preview: false,
                mime_type: 'application/pdf',
                file_url: '/organization/documents/files/7325/preview',
            }),
            'unsupported',
        );
    });
});

describe('resolveDocumentPreviewMimeType', () => {
    it('infers pdf from original filename when mime is missing', () => {
        assert.equal(
            resolveDocumentPreviewMimeType(
                null,
                productionDocument7325.file_url,
                productionDocument7325.original_filename,
            ),
            'application/pdf',
        );
    });
});
