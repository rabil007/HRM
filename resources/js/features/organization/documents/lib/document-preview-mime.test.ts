import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    documentPreviewIsAvailable,
    resolveDocumentPreviewMimeType,
} from './document-preview-mime.ts';

describe('resolveDocumentPreviewMimeType', () => {
    it('infers pdf from original filename when mime is missing', () => {
        assert.equal(
            resolveDocumentPreviewMimeType(
                null,
                '/organization/documents/files/7325/preview',
                'hse-certificate.pdf',
            ),
            'application/pdf',
        );
    });

    it('infers image types from filenames', () => {
        assert.equal(
            resolveDocumentPreviewMimeType(null, '/preview', 'photo.png'),
            'image/png',
        );
    });
});

describe('documentPreviewIsAvailable', () => {
    it('allows preview when backend can_preview is true without mime type', () => {
        assert.equal(
            documentPreviewIsAvailable({
                can_preview: true,
                file_url: '/organization/documents/files/7325/preview',
                original_filename: 'hse-certificate.pdf',
            }),
            true,
        );
    });

    it('blocks preview when can_preview is false and mime cannot be inferred', () => {
        assert.equal(
            documentPreviewIsAvailable({
                can_preview: false,
                file_url: '/organization/documents/files/99/preview',
            }),
            false,
        );
    });
});
