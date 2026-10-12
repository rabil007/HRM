import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    candidateDetailSectionHref,
    parseCandidateDetailHash,
    sectionKeyForHash,
} from './candidate-section-hash.ts';

describe('candidate-section-hash', () => {
    it('parses known detail hashes and ignores others', () => {
        assert.equal(parseCandidateDetailHash('#offer-jol'), 'offer-jol');
        assert.equal(parseCandidateDetailHash('joining'), 'joining');
        assert.equal(parseCandidateDetailHash('#interview'), 'interview');
        assert.equal(parseCandidateDetailHash('#profile'), null);
        assert.equal(parseCandidateDetailHash(''), null);
    });

    it('maps hashes to collapsible section keys', () => {
        assert.equal(sectionKeyForHash('offer-jol'), 'offer');
        assert.equal(sectionKeyForHash('joining'), 'joining');
        assert.equal(sectionKeyForHash('interview'), 'interview');
    });

    it('builds candidate detail section hrefs', () => {
        assert.equal(
            candidateDetailSectionHref(12, 'offer-jol'),
            '/organization/recruitment/candidates/12#offer-jol',
        );
        assert.equal(
            candidateDetailSectionHref(12, 'joining'),
            '/organization/recruitment/candidates/12#joining',
        );
    });
});
