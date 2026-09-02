import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

function readFeature(relativePath: string): string {
    const here = path.dirname(fileURLToPath(import.meta.url));

    return readFileSync(path.join(here, relativePath), 'utf8');
}

describe('requests and approval flows UI', () => {
    it('keeps request filters labelled and settings in the page header', () => {
        const requests = readFeature(
            '../workflow/document-requests-content.tsx',
        );

        assert.equal(requests.includes('value={reviewStatusValue}'), true);
        assert.equal(requests.includes('value={reviewActionValue}'), true);
        assert.equal(requests.includes('filterSelectValue'), true);
        assert.equal(requests.includes("'__all__'"), true);
        assert.equal(
            requests.includes('className="mb-0 min-w-0 flex-1"'),
            true,
        );
        assert.equal(requests.includes('Approval Flows'), true);
        assert.equal(requests.includes('Configure approval flows'), true);
        assert.equal(requests.includes('PageHeader'), true);
        assert.equal(requests.includes('right={headerActions}'), true);
    });

    it('renders approval flow routing without overlapping the updated column', () => {
        const presets = readFeature(
            '../workflow/document-workflow-presets-content.tsx',
        );
        const routing = readFeature(
            '../workflow/workflow-preset-routing-steps.tsx',
        );

        assert.equal(presets.includes('table-fixed'), true);
        assert.equal(presets.includes('WorkflowPresetRoutingSteps'), true);
        assert.equal(presets.includes('TableRowActions'), true);
        assert.equal(presets.includes('DropdownMenu'), false);
        assert.equal(presets.includes('whitespace-nowrap'), true);
        assert.equal(presets.includes('No approval flows yet'), true);
        assert.equal(routing.includes('stage.action_label'), true);
        assert.equal(routing.includes('target.label'), true);
        assert.equal(routing.includes('title={summary}'), true);
    });
});
