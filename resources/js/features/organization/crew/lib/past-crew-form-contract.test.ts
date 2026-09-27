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
        assert.match(periodFields, /Home Date/);
        assert.match(periodFields, /Accommodation/);
        assert.doesNotMatch(periodFields, /Check-In/);
        assert.doesNotMatch(periodFields, /Check-Out/);
        assert.doesNotMatch(periodFields, /hotel_check_in/);
        assert.doesNotMatch(periodFields, /hotel_check_out/);
        assert.doesNotMatch(periodFields, /Home \/ Available From/);
        assert.doesNotMatch(periodFields, /Pre-Mobilisation/);
        assert.doesNotMatch(periodFields, /Training Start/);
    });

    it('keeps hotel controls behind Accommodation = Hotel on standby only', () => {
        const periodFields = readFeature('actions/past-crew-period-fields.tsx');

        assert.match(periodFields, /choice === 'hotel'/);
        assert.match(periodFields, /prefix="sign_on"/);
        assert.match(periodFields, /prefix="sign_off"/);
        assert.match(periodFields, /Hotel \*/);
        assert.match(periodFields, /Room Type/);
        assert.match(periodFields, /room\.hotel_id === Number\(hotelId\)/);
        assert.doesNotMatch(periodFields, /room\.hotel_id === null/);

        const onsiteSection = periodFields.slice(
            periodFields.indexOf('title="Onsite / On Vessel"'),
            periodFields.indexOf('title="Sign-Off Standby"'),
        );

        assert.doesNotMatch(onsiteSection, /AccommodationFields/);
        assert.doesNotMatch(onsiteSection, /Accommodation/);
    });

    it('uses simplified Import Past Crew Data wording', () => {
        const panel = readFeature('actions/historical-import-excel-panel.tsx');

        assert.match(panel, /Import Past Crew Data/);
        assert.match(panel, /Enter Past Crew Data/);
        assert.match(panel, /Check File/);
        assert.match(panel, /Review Past Crew Data/);
        assert.match(panel, /Importing Past Crew Data/);
        assert.doesNotMatch(panel, /Import Historical Crew Data/);
        assert.doesNotMatch(panel, /Validate File/);
        assert.doesNotMatch(panel, /Historical Import Validation/);
        assert.doesNotMatch(panel, /historical assignments using Reference/);
    });
});
