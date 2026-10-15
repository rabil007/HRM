import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const componentPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'vessel-transfer-recommendation-dialog.tsx',
);

describe('VesselTransferRecommendationDialog', () => {
    it('describes the current assignment as P2 On Vessel', () => {
        const source = readFileSync(componentPath, 'utf8');

        assert.equal(source.includes('P2 On Vessel'), true);
        assert.equal(source.includes('P4 On Vessel'), false);
        assert.equal(source.includes('active P4'), false);
    });
});
