import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pdfEmbedUrl } from './pdf-embed-url.ts';

describe('pdfEmbedUrl', () => {
    it('hides the browser PDF chrome when the url has no hash', () => {
        assert.equal(
            pdfEmbedUrl('https://example.test/files/letter.pdf'),
            'https://example.test/files/letter.pdf#toolbar=0&navpanes=0&scrollbar=0',
        );
    });

    it('keeps query tokens and does not replace an existing hash', () => {
        assert.equal(
            pdfEmbedUrl('https://example.test/files/letter.pdf?token=abc'),
            'https://example.test/files/letter.pdf?token=abc#toolbar=0&navpanes=0&scrollbar=0',
        );
        assert.equal(
            pdfEmbedUrl('https://example.test/files/letter.pdf#page=2'),
            'https://example.test/files/letter.pdf#page=2',
        );
    });
});
