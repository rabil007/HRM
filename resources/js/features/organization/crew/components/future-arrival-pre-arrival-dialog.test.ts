import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const componentPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'future-arrival-pre-arrival-dialog.tsx',
);

describe('FutureArrivalPreArrivalDialog', () => {
    it('uses modern Pre-Arrival labels and does not mention Pre-Mobilisation', () => {
        const source = readFileSync(componentPath, 'utf8');

        assert.ok(source.includes('Start Pre-Arrival'));
        assert.ok(source.includes('P0 — Pre-Arrival'));
        assert.ok(source.includes('start this assignment in'));
        assert.ok(source.includes('Pre-Arrival instead?'));
        assert.equal(source.includes('Pre-Mobilisation'), false);
        assert.equal(source.includes('Skip Pre-Mobilisation'), false);
    });
});
