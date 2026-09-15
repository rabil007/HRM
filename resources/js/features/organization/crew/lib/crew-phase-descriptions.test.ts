import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { CREW_DIRECT_START_STAGES } from '../types.ts';
import {
    CREW_PHASE_CODES,
    crewPhaseCopy,
    crewPhaseDescription,
    crewPhaseGuideItems,
} from './crew-phase-descriptions.ts';

describe('crew phase descriptions', () => {
    it('covers every P0–P6 code with a label, description, and compact guide line', () => {
        const items = crewPhaseGuideItems();

        assert.deepEqual(
            items.map((item) => item.code),
            [...CREW_PHASE_CODES],
        );
        assert.equal(items.length, 8);

        for (const item of items) {
            assert.ok(item.label.length > 0);
            assert.ok(item.description.length > 0);
            assert.ok(item.compact.length > 0);
            assert.equal(crewPhaseDescription(item.code), item.description);
        }
    });

    it('explains join standby as waiting or hotel/accommodation before joining', () => {
        const copy = crewPhaseCopy('p2a');

        assert.equal(
            copy?.description,
            'Waiting or staying in hotel/accommodation before joining the vessel.',
        );
        assert.equal(copy?.compact, 'Waiting/hotel before joining');
        assert.match(copy?.description ?? '', /waiting/i);
        assert.match(copy?.description ?? '', /hotel\/accommodation/i);
        assert.match(copy?.description ?? '', /before joining/i);
    });

    it('explains demobilisation standby as disembarked and waiting or hotel/accommodation', () => {
        const copy = crewPhaseCopy('p5');

        assert.equal(
            copy?.description,
            'Disembarked and waiting or staying in hotel/accommodation for onward or home travel.',
        );
        assert.equal(copy?.compact, 'Waiting/hotel after disembarkation');
        assert.match(copy?.description ?? '', /disembarked/i);
        assert.match(copy?.description ?? '', /hotel\/accommodation/i);
    });

    it('uses the selected current-stage description for the create selector', () => {
        const expected: Record<string, string> = {
            p0: 'Preparing the crew member before travel.',
            p1: 'Travelling to the joining location.',
        };

        assert.deepEqual(
            CREW_DIRECT_START_STAGES.map((stage) => stage.value),
            ['p1', 'p0'],
        );

        for (const stage of CREW_DIRECT_START_STAGES) {
            assert.equal(
                crewPhaseDescription(stage.value),
                expected[stage.value],
            );
        }
    });

    it('includes every supported phase in the compact phase guide', () => {
        const guide = crewPhaseGuideItems().map(
            (item) => `${item.code.toUpperCase()} — ${item.compact}`,
        );

        assert.deepEqual(guide, [
            'P0 — Prepare before travel',
            'P1 — Travelling to join',
            'P2A — Waiting/hotel before joining',
            'P2B — Training',
            'P3 — Ready to board',
            'P4 — On vessel',
            'P5 — Waiting/hotel after disembarkation',
            'P6 — Home / redeployment',
        ]);
    });

    it('fails safely for unknown, empty, or missing phase codes', () => {
        assert.equal(crewPhaseCopy(null), null);
        assert.equal(crewPhaseCopy(undefined), null);
        assert.equal(crewPhaseCopy(''), null);
        assert.equal(crewPhaseCopy('unknown'), null);
        assert.equal(crewPhaseCopy('p7'), null);
        assert.equal(crewPhaseDescription('draft'), null);
        assert.equal(crewPhaseCopy('P2A')?.code, 'p2a');
    });
});
