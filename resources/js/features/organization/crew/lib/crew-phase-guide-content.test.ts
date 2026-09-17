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
            ['p0', 'p2a', 'p2b', 'p4', 'p5', 'p6'],
        );
    });

    it('documents P1 and P3 as compatibility phases', () => {
        assert.deepEqual(
            crewPhaseGuideCompatibilityItems().map((item) => item.code),
            ['p1', 'p3'],
        );
        assert.match(
            crewPhaseGuideCompatibilityItems()[0]?.compatibilityNote ?? '',
            /Legacy compatibility/i,
        );
    });

    it('states that not every assignment uses every phase', () => {
        assert.match(CREW_PHASE_GUIDE_FOOTNOTE, /Not every assignment/i);
    });

    it('shows transfer only as a P4 alternative path', () => {
        const p4 = crewPhaseGuideItems().find((item) => item.code === 'p4');

        assert.match(p4?.alternativePath ?? '', /Transfer Vessel/i);
        assert.equal(
            crewPhaseGuideItems().find((item) => item.code === 'p2a')
                ?.alternativePath,
            'Send to Training. Changing vessel here updates the current mobilisation — not Transfer Vessel.',
        );
    });

    it('explains redeploy for P5 and P6', () => {
        assert.match(
            crewPhaseGuideItems().find((item) => item.code === 'p5')
                ?.alternativePath ?? '',
            /Redeploy/i,
        );
        assert.match(
            crewPhaseGuideItems().find((item) => item.code === 'p6')
                ?.alternativePath ?? '',
            /Redeploy/i,
        );
    });
});
