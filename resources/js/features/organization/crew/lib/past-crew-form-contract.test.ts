import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function readFeature(relativePath: string): string {
    return readFileSync(join(root, relativePath), 'utf8');
}

describe('Past Crew Data form contract', () => {
    it('removes legacy detailed phase fields from the Add Past Data dialog', () => {
        const dialog = readFeature('actions/add-past-data-dialog.tsx');
        const periodFields = readFeature('actions/past-crew-period-fields.tsx');

        assert.doesNotMatch(dialog, /Pre-Mobilisation/);
        assert.doesNotMatch(dialog, /Training Start/);
        assert.doesNotMatch(dialog, /Training End/);
        assert.doesNotMatch(dialog, /Ready to Join/);
        assert.doesNotMatch(dialog, /Add Historical Assignment/);
        assert.match(dialog, /Save Past Crew Data/);

        assert.match(periodFields, /Sign-On Standby/);
        assert.match(periodFields, /Onsite \/ On Vessel/);
        assert.match(periodFields, /Sign-Off Standby/);
        assert.match(periodFields, /Home \/ Available From/);
        assert.match(periodFields, /Accommodation/);
        assert.doesNotMatch(periodFields, /Pre-Mobilisation/);
        assert.doesNotMatch(periodFields, /Training Start/);
    });

    it('keeps hotel controls behind Accommodation = Hotel on standby only', () => {
        const periodFields = readFeature('actions/past-crew-period-fields.tsx');

        assert.match(periodFields, /choice === 'hotel'/);
        assert.match(periodFields, /prefix="sign_on"/);
        assert.match(periodFields, /prefix="sign_off"/);
        assert.match(periodFields, /room\.hotel_id === Number\(hotelId\)/);
        assert.doesNotMatch(periodFields, /room\.hotel_id === null/);

        const onsiteSection = periodFields.slice(
            periodFields.indexOf('title="Onsite / On Vessel"'),
            periodFields.indexOf('title="Sign-Off Standby"'),
        );

        assert.doesNotMatch(onsiteSection, /AccommodationFields/);
        assert.doesNotMatch(onsiteSection, /Accommodation/);
    });
});
