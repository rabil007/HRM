import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    tokenizeWhatsAppPreviewBody,
    tokenizeWhatsAppPreviewFormatting,
} from './whatsapp-preview-formatting.ts';

describe('whatsapp preview formatting', () => {
    it('tokenizes bold and italic markers without changing plain text', () => {
        assert.deepEqual(
            tokenizeWhatsAppPreviewFormatting('Hello *OMS* and _crew_'),
            [
                { type: 'text', value: 'Hello ' },
                { type: 'bold', value: 'OMS' },
                { type: 'text', value: ' and ' },
                { type: 'italic', value: 'crew' },
            ],
        );
    });

    it('preserves urls and newlines in the body shell', () => {
        const body =
            "Here's an update from OMS:\n\n*Share this*\nhttps://example.com/promo";

        assert.deepEqual(tokenizeWhatsAppPreviewBody(body), [
            { type: 'text', value: "Here's an update from OMS:\n\n" },
            { type: 'bold', value: 'Share this' },
            { type: 'text', value: '\n' },
            { type: 'link', value: 'https://example.com/promo' },
        ]);
    });
});
