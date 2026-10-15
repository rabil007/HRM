import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const pagePath = join(dirname(fileURLToPath(import.meta.url)), 'show.tsx');

describe('Employee Training show', () => {
    it('labels Crew Operations sourced records as P1B Training', () => {
        const source = readFileSync(pagePath, 'utf8');

        assert.ok(source.includes('Crew Operations · P1B'));
        assert.ok(source.includes('Source: Crew Operations · P1B Training'));
        assert.ok(source.includes('Crew Operations (P1B)'));
        assert.equal(source.includes('P2B'), false);
    });
});
