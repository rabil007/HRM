import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    CREW_PHASE_GUIDE_FOOTNOTE,
    crewPhaseGuideCompatibilityItems,
    crewPhaseGuideFlowItems,
    crewPhaseGuideItems,
} from './crew-phase-guide-content.ts';

describe('crew phase guide content', () => {
    it('includes the primary operational flow phases', () => {
        assert.deepEqual(
            crewPhaseGuideFlowItems().map((item) => item.code),
            ['p0', 'p1a', 'p1b', 'p2', 'p3', 'p4'],
        );
    });

    it('does not document retired Travel In or Ready to Join compatibility phases', () => {
        assert.deepEqual(
            crewPhaseGuideCompatibilityItems().map((item) => item.code),
            [],
        );
    });

    it('states that not every assignment uses every phase', () => {
        assert.match(CREW_PHASE_GUIDE_FOOTNOTE, /Not every assignment/i);
    });

    it('shows transfer only as a P2 alternative path', () => {
        const p2 = crewPhaseGuideItems().find((item) => item.code === 'p2');

        assert.match(p2?.alternativePath ?? '', /Transfer Vessel/i);
        assert.equal(
            crewPhaseGuideItems().find((item) => item.code === 'p1a')
                ?.alternativePath,
            'Send to Training. Changing vessel here updates the current mobilisation — not Transfer Vessel.',
        );
    });

    it('explains redeploy for P3 and P4', () => {
        assert.match(
            crewPhaseGuideItems().find((item) => item.code === 'p3')
                ?.alternativePath ?? '',
            /Redeploy/i,
        );
        assert.match(
            crewPhaseGuideItems().find((item) => item.code === 'p4')
                ?.alternativePath ?? '',
            /Redeploy/i,
        );
    });
});
