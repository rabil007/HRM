import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    commitStroke,
    signaturePayloadFromStrokes,
    undoLastStroke,
} from './signature-strokes.ts';

const here = dirname(fileURLToPath(import.meta.url));

describe('signature strokes', () => {
    it('commits a stroke and undoes the last one', () => {
        const first = [
            { x: 1, y: 1 },
            { x: 2, y: 2 },
        ];
        const second = [{ x: 8, y: 8 }];
        const committed = commitStroke(commitStroke([], first), second);

        assert.deepEqual(committed, [first, second]);
        assert.deepEqual(undoLastStroke(committed), [first]);
        assert.deepEqual(undoLastStroke([first]), []);
        assert.deepEqual(undoLastStroke([]), []);
    });

    it('ignores empty strokes and yields no payload when the pad is empty', () => {
        assert.deepEqual(commitStroke([], null), []);
        assert.deepEqual(commitStroke([], []), []);
        assert.equal(
            signaturePayloadFromStrokes([], () => 'data:image/png;base64,abc'),
            null,
        );
        assert.equal(
            signaturePayloadFromStrokes([[{ x: 1, y: 1 }]], () => 'data:ok'),
            'data:ok',
        );
    });
});

describe('signing capture ui', () => {
    it('keeps an uploaded signature preview in the capture component', () => {
        const source = readFileSync(
            join(here, 'signature-capture.tsx'),
            'utf8',
        );

        assert.equal(source.includes('uploadedPreview'), true);
        assert.equal(source.includes('setUploadedPreview(dataUrl)'), true);
        assert.equal(source.includes('Uploaded signature preview'), true);
        assert.equal(source.includes('displayPreview'), true);
        assert.equal(source.includes('hideClear'), false);
    });

    it('exposes undo on the signature pad', () => {
        const source = readFileSync(
            join(here, '../../components/signature-pad.tsx'),
            'utf8',
        );

        assert.equal(source.includes('Undo last stroke'), true);
        assert.equal(source.includes('undoLastStroke'), true);
    });
});
