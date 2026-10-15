import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const componentPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'planning-start-active-assignment-conflict.tsx',
);

describe('PlanningStartActiveAssignmentConflict', () => {
    it('labels an onboard conflict as Active P2 On Vessel', () => {
        const source = readFileSync(componentPath, 'utf8');

        assert.equal(source.includes('Active P2 On Vessel'), true);
        assert.equal(source.includes('Active P4 On Vessel'), false);
    });
});
