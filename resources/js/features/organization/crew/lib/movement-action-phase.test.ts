import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    confirmDisembarkationOmitsAccommodation,
    redeployCapturesDestinationAccommodation,
    resolveInitialMovementNextPhase,
} from './movement-action-phase.ts';

describe('movement action phase codes', () => {
    it('starts record arrival and complete training at pre-joining standby', () => {
        assert.equal(
            resolveInitialMovementNextPhase('record_arrival', 'p0'),
            'p1a',
        );
        assert.equal(
            resolveInitialMovementNextPhase('record_arrival', 'p1'),
            'p1a',
        );
        assert.equal(
            resolveInitialMovementNextPhase('complete_training', 'p1b'),
            'p1a',
        );
    });

    it('starts confirm disembarkation at demobilisation standby', () => {
        assert.equal(
            resolveInitialMovementNextPhase('confirm_disembarkation', 'p2'),
            'p3',
        );
    });

    it('omits accommodation only for direct home disembarkation', () => {
        assert.equal(confirmDisembarkationOmitsAccommodation('p4'), true);
        assert.equal(confirmDisembarkationOmitsAccommodation('p3'), false);
        assert.equal(confirmDisembarkationOmitsAccommodation('p6'), false);
    });

    it('captures destination accommodation only for p1a redeploy', () => {
        assert.equal(redeployCapturesDestinationAccommodation('p1a'), true);
        assert.equal(redeployCapturesDestinationAccommodation('p2a'), false);
        assert.equal(redeployCapturesDestinationAccommodation('p0'), false);
        assert.equal(redeployCapturesDestinationAccommodation('p2'), false);
    });
});
