import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { recommendsVesselTransfer } from './vessel-transfer-recommendation.ts';

const currentOnVessel = { vessel_id: 638 };

describe('recommendsVesselTransfer', () => {
    it('does not recommend a transfer when there is no current On Vessel assignment', () => {
        assert.equal(recommendsVesselTransfer(null, 649), false);
    });

    it('does not recommend a transfer when the destination vessel is not selected', () => {
        assert.equal(recommendsVesselTransfer(currentOnVessel, null), false);
    });

    it('does not recommend a transfer for an invalid destination vessel', () => {
        assert.equal(recommendsVesselTransfer(currentOnVessel, 0), false);
        assert.equal(recommendsVesselTransfer(currentOnVessel, -1), false);
    });

    it('does not recommend a transfer when the selected vessel is the current vessel', () => {
        assert.equal(recommendsVesselTransfer(currentOnVessel, 638), false);
    });

    it('recommends a transfer when a different destination vessel is selected', () => {
        assert.equal(recommendsVesselTransfer(currentOnVessel, 649), true);
    });
});
