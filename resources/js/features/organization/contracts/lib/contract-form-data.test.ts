import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function readFeature(relativePath: string): string {
    return readFileSync(join(root, relativePath), 'utf8');
}

describe('contract form shared helpers', () => {
    it('keeps payload builders free of salary-revision controls', () => {
        const formData = readFeature('lib/contract-form-data.ts');
        const formFields = readFeature('components/contract-form-fields.tsx');
        const formSheet = readFeature('components/contract-form-sheet.tsx');

        assert.match(formData, /export function buildContractPayload/);
        assert.doesNotMatch(formData, /revision_effective_from/);
        assert.doesNotMatch(formData, /revision_apply_to_payroll/);
        assert.doesNotMatch(formFields, /Salary revision for this edit/);
        assert.doesNotMatch(formFields, /EOS \(Monthly, optional\)/);
        assert.match(formFields, />\s*EOS\s*</);
        assert.match(formFields, /compensationLocked/);
        assert.match(formSheet, /compensationLocked=\{mode === 'edit'\}/);
        assert.match(formSheet, /SheetContent/);
    });
});
