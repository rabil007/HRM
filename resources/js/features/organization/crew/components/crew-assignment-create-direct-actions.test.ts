import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const componentPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'crew-assignment-create-direct-actions.tsx',
);

describe('CrewAssignmentCreateDirectActions', () => {
    it('renders footer action labels in the required order', () => {
        const source = readFileSync(componentPath, 'utf8');

        const alreadyIndex = source.indexOf('Already Arrived');
        const preArrivalIndex = source.indexOf('Start Pre-Arrival');
        const draftIndex = source.indexOf('Save as Draft');

        assert.ok(alreadyIndex >= 0);
        assert.ok(preArrivalIndex > alreadyIndex);
        assert.ok(draftIndex > preArrivalIndex);
        assert.equal(source.includes('Start Pre-Mobilisation'), false);
        assert.equal(source.includes('Pre-Mobilisation'), false);
    });

    it('does not wire hover or focus handlers that mutate form state', () => {
        const source = readFileSync(componentPath, 'utf8');

        assert.equal(source.includes('onPointerEnter'), false);
        assert.equal(source.includes('onFocus'), false);
        assert.equal(source.includes('onPrepareAlreadyArrivedUi'), false);
    });
});
