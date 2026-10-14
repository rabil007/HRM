import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

const componentPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'crew-assignment-create-direct-actions.tsx',
);

describe('CrewAssignmentCreateDirectActions', () => {
    it('renders footer action labels in the required order', () => {
        const source = readFileSync(componentPath, 'utf8');

        const alreadyIndex = source.indexOf('Already Arrived');
        const preMobIndex = source.indexOf('Start Pre-Mobilisation');
        const draftIndex = source.indexOf('Save as Draft');

        assert.ok(alreadyIndex >= 0);
        assert.ok(preMobIndex > alreadyIndex);
        assert.ok(draftIndex > preMobIndex);
    });

    it('does not wire hover or focus handlers that mutate form state', () => {
        const source = readFileSync(componentPath, 'utf8');

        assert.equal(source.includes('onPointerEnter'), false);
        assert.equal(source.includes('onFocus'), false);
        assert.equal(source.includes('onPrepareAlreadyArrivedUi'), false);
    });
});
