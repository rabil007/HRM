import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const pagePath = join(dirname(fileURLToPath(import.meta.url)), 'settings.tsx');

describe('Crew Operations settings', () => {
    it('describes training sync using the modern P1B phase', () => {
        const source = readFileSync(pagePath, 'utf8');

        assert.ok(source.includes('Sync P1B Training to Employee'));
        assert.ok(source.includes('completing a P1B'));
        assert.equal(source.includes('P2B'), false);
        assert.equal(source.includes('Sync P2B Training'), false);
    });
});
