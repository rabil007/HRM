import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const formPath = join(
    dirname(fileURLToPath(import.meta.url)),
    'crew-assignment-create-form.tsx',
);

describe('CrewAssignmentCreateForm client arrival errors', () => {
    it('clears client arrival errors when arrival dates are edited', () => {
        const source = readFileSync(formPath, 'utf8');

        assert.match(
            source,
            /id="planning-planned-arrival-at"[\s\S]*?setClientArrivalError\(\s*undefined,\s*\)/,
        );
        assert.match(
            source,
            /previous\?\.planned_arrival_at !==[\s\S]*?index === 0[\s\S]*?setClientArrivalError\(\s*undefined,\s*\)/,
        );
    });

    it('scopes default arrival dates to single-create initial mode', () => {
        const source = readFileSync(formPath, 'utf8');

        assert.match(source, /shouldDefaultSingleCreateArrivalDate/);
        assert.match(source, /allowDefaultSingleArrivalDate/);
    });
});
