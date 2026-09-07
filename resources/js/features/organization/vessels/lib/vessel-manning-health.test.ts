import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    vesselManningHealthBadgeClass,
    vesselManningHealthDot,
} from './vessel-manning-health.ts';

describe('vessel manning health presentation', () => {
    it('maps each status to a distinct badge class and marker', () => {
        assert.equal(vesselManningHealthDot('healthy'), '🟢');
        assert.equal(vesselManningHealthDot('at_risk'), '🟠');
        assert.equal(vesselManningHealthDot('critical'), '🔴');
        assert.equal(vesselManningHealthDot('not_configured'), '⚪');
        assert.match(vesselManningHealthBadgeClass('critical'), /red/);
        assert.match(vesselManningHealthBadgeClass('at_risk'), /amber/);
        assert.match(vesselManningHealthBadgeClass('healthy'), /emerald/);
    });
});
