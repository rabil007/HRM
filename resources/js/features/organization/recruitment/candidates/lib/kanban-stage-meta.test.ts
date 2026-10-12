import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { daysInStageLabel, getInitials } from './kanban-stage-meta.ts';

describe('kanban-stage-meta', () => {
    it('formats days in stage labels', () => {
        assert.equal(daysInStageLabel(null), null);
        assert.equal(daysInStageLabel(undefined), null);
        assert.equal(daysInStageLabel(0), 'Today');
        assert.equal(daysInStageLabel(1), '1d waiting');
        assert.equal(daysInStageLabel(3), '3d waiting');
    });

    it('derives initials from names', () => {
        assert.equal(getInitials('Jane'), 'JA');
        assert.equal(getInitials('Jane Applicant'), 'JA');
        assert.equal(getInitials('Ada Lovelace Byron'), 'AB');
    });
});
