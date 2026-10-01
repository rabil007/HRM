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
    it('removes legacy detailed phase fields and accommodation from the Add Past Data dialog', () => {
        const dialog = readFeature('actions/add-past-data-dialog.tsx');
        const periodFields = readFeature('actions/past-crew-period-fields.tsx');

        assert.doesNotMatch(dialog, /Pre-Mobilisation/);
        assert.doesNotMatch(dialog, /Training Start/);
        assert.doesNotMatch(dialog, /Training End/);
        assert.doesNotMatch(dialog, /Ready to Join/);
        assert.doesNotMatch(dialog, /Add Historical Assignment/);
        assert.doesNotMatch(dialog, /Crew \/ Assignment Details/);
        assert.doesNotMatch(dialog, /sign_on_accommodation/);
        assert.doesNotMatch(dialog, /sign_off_accommodation/);
        assert.match(dialog, /Crew Details/);
        assert.match(dialog, /Save Past Crew Data/);

        assert.match(periodFields, /Sign-On Standby/);
        assert.match(periodFields, /Onsite \/ On Vessel/);
        assert.match(periodFields, /Sign-Off Standby/);
        assert.match(periodFields, /Home Date/);
        assert.doesNotMatch(periodFields, /Accommodation/);
        assert.doesNotMatch(periodFields, /Hotel/);
        assert.doesNotMatch(periodFields, /Room Type/);
        assert.doesNotMatch(periodFields, /Check-In/);
        assert.doesNotMatch(periodFields, /Check-Out/);
        assert.doesNotMatch(periodFields, /hotel_check_in/);
        assert.doesNotMatch(periodFields, /hotel_check_out/);
        assert.doesNotMatch(periodFields, /Home \/ Available From/);
        assert.doesNotMatch(periodFields, /Pre-Mobilisation/);
        assert.doesNotMatch(periodFields, /Training Start/);
    });

    it('has no accommodation, hotel, or room type controls anywhere in period fields', () => {
        const periodFields = readFeature('actions/past-crew-period-fields.tsx');

        assert.doesNotMatch(periodFields, /AccommodationFields/);
        assert.doesNotMatch(periodFields, /Accommodation/);
        assert.doesNotMatch(periodFields, /Hotel/);
        assert.doesNotMatch(periodFields, /Room Type/);
        assert.doesNotMatch(periodFields, /not_recorded/);
        assert.doesNotMatch(periodFields, /no_accommodation/);
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

    it('does not expose hotels or room types in HistoricalFormOptions', () => {
        const types = readFeature('types.ts');
        const optionsBlock = types.slice(
            types.indexOf('export interface HistoricalFormOptions {'),
            types.indexOf('export type HistoricalImportRowStatus'),
        );

        assert.match(optionsBlock, /employees:/);
        assert.match(optionsBlock, /positions:/);
        assert.match(optionsBlock, /vessels:/);
        assert.match(optionsBlock, /clients:/);
        assert.match(optionsBlock, /company_timezone:/);
        assert.doesNotMatch(optionsBlock, /hotels/);
        assert.doesNotMatch(optionsBlock, /room_types/);
    });
});
