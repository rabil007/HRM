import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    CREW_PHASE_CODES,
    LEGACY_CREW_PHASE_CODES,
    crewPhaseCopy,
    crewPhaseDescription,
    crewPhaseGuideItems,
} from './crew-phase-descriptions.ts';

describe('crew phase descriptions', () => {
    it('covers every modern phase code with a label, description, and compact guide line', () => {
        const items = crewPhaseGuideItems();

        assert.deepEqual(
            items.map((item) => item.code),
            [...CREW_PHASE_CODES],
        );
        assert.equal(items.length, 6);

        for (const item of items) {
            assert.ok(item.label.length > 0);
            assert.ok(item.description.length > 0);
            assert.ok(item.compact.length > 0);
            assert.equal(crewPhaseDescription(item.code), item.description);
        }
    });

    it('explains pre-arrival as waiting for arrival in the arrival-first workflow', () => {
        const copy = crewPhaseCopy('p0');

        assert.equal(
            copy?.description,
            'Assignment prepared and waiting for the crew member to arrive.',
        );
        assert.equal(copy?.label, 'Pre-Arrival');
        assert.equal(copy?.compact, 'Waiting for arrival');
        assert.match(copy?.description ?? '', /waiting/i);
        assert.match(copy?.description ?? '', /arrive/i);
        assert.doesNotMatch(copy?.label ?? '', /Mobilisation/i);
    });

    it('explains pre-joining standby as waiting or hotel/accommodation before joining', () => {
        const copy = crewPhaseCopy('p1a');

        assert.equal(
            copy?.description,
            'Waiting or staying in hotel/accommodation before joining the vessel.',
        );
        assert.equal(copy?.compact, 'Pre-joining standby');
        assert.match(copy?.description ?? '', /waiting/i);
        assert.match(copy?.description ?? '', /hotel\/accommodation/i);
        assert.match(copy?.description ?? '', /before joining/i);
    });

    it('explains demobilisation standby as disembarked and waiting or hotel/accommodation', () => {
        const copy = crewPhaseCopy('p3');

        assert.equal(
            copy?.description,
            'Disembarked and waiting or staying in hotel/accommodation for onward or home travel.',
        );
        assert.equal(copy?.compact, 'Demob standby');
        assert.match(copy?.description ?? '', /disembarked/i);
        assert.match(copy?.description ?? '', /hotel\/accommodation/i);
    });

    it('includes every supported phase in the compact phase guide', () => {
        const guide = crewPhaseGuideItems().map(
            (item) => `${item.code.toUpperCase()} — ${item.compact}`,
        );

        assert.deepEqual(guide, [
            'P0 — Waiting for arrival',
            'P1A — Pre-joining standby',
            'P1B — Training',
            'P2 — On vessel',
            'P3 — Demob standby',
            'P4 — Home / redeploy',
        ]);
    });

    it('fails safely for unknown, empty, or missing phase codes', () => {
        assert.equal(crewPhaseCopy(null), null);
        assert.equal(crewPhaseCopy(undefined), null);
        assert.equal(crewPhaseCopy(''), null);
        assert.equal(crewPhaseCopy('unknown'), null);
        assert.equal(crewPhaseCopy('p7'), null);
        assert.equal(crewPhaseDescription('draft'), null);
        assert.equal(crewPhaseCopy('P1A')?.code, 'p1a');
    });

    it('maps legacy p2a display copy without treating it as the modern onboard p2 code', () => {
        const legacy = crewPhaseCopy('p2a');
        const onboard = crewPhaseCopy('p2');

        assert.notEqual(legacy?.label, onboard?.label);
        assert.match(legacy?.label ?? '', /legacy/i);
        assert.equal(onboard?.code, 'p2');
        assert.equal(onboard?.label, 'On Vessel');
    });

    it('never classifies modern p3 or p4 as exclusive legacy codes', () => {
        assert.deepEqual(LEGACY_CREW_PHASE_CODES, [
            'p1',
            'p2a',
            'p2b',
            'p5',
            'p6',
        ]);
        assert.equal(LEGACY_CREW_PHASE_CODES.includes('p3' as never), false);
        assert.equal(LEGACY_CREW_PHASE_CODES.includes('p4' as never), false);
        assert.equal(crewPhaseCopy('p3')?.label, 'Demobilisation Standby');
        assert.equal(crewPhaseCopy('p4')?.label, 'Home / Redeployment');
    });
});
