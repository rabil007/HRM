import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    joiningFieldError,
    joiningKnownGeneralError,
    joiningUnrenderedErrors,
} from './joining-form-errors.ts';

describe('joining-form-errors', () => {
    it('extracts known general errors like candidate, stage, offer, readiness, and lock_version', () => {
        assert.equal(
            joiningKnownGeneralError({
                candidate:
                    'Offers cannot be revised while candidate is in Joined stage. Undo joined first if revision is required.',
            }),
            'Offers cannot be revised while candidate is in Joined stage. Undo joined first if revision is required.',
        );

        assert.equal(
            joiningKnownGeneralError({
                stage: 'Candidate joining readiness can only be updated in Joining stage.',
            }),
            'Candidate joining readiness can only be updated in Joining stage.',
        );

        assert.equal(
            joiningKnownGeneralError({
                offer: 'The candidate current offer must be Accepted to confirm joined.',
            }),
            'The candidate current offer must be Accepted to confirm joined.',
        );

        assert.equal(
            joiningKnownGeneralError({
                readiness:
                    'Candidate readiness must be Ready before confirming joined.',
            }),
            'Candidate readiness must be Ready before confirming joined.',
        );

        assert.equal(
            joiningKnownGeneralError({
                lock_version: 'The candidate was modified by another user.',
            }),
            'The candidate was modified by another user.',
        );

        assert.equal(joiningKnownGeneralError({}), null);
    });

    it('extracts field errors for known joining fields', () => {
        assert.equal(
            joiningFieldError(
                {
                    actual_joining_date:
                        'The actual joining date cannot be in the future.',
                },
                'actual_joining_date',
            ),
            'The actual joining date cannot be in the future.',
        );

        assert.equal(
            joiningFieldError(
                {
                    expected_joining_date: [
                        'The expected joining date format is invalid.',
                    ],
                },
                'expected_joining_date',
            ),
            'The expected joining date format is invalid.',
        );

        assert.equal(
            joiningFieldError(
                {
                    reason: 'A reason of at least 3 characters is required to correct candidate joining.',
                },
                'reason',
            ),
            'A reason of at least 3 characters is required to correct candidate joining.',
        );

        assert.equal(joiningFieldError({}, 'actual_joining_date'), undefined);
    });

    it('collects unrendered errors that do not match field or general keys in fallback', () => {
        const unrendered = joiningUnrenderedErrors({
            actual_joining_date: 'Actual joining date error',
            candidate: 'Candidate stage error',
            unmapped_check: 'Unmapped verification failure',
            another_unmapped: ['Another unmapped joining error'],
        });

        assert.deepEqual(unrendered, [
            'Unmapped verification failure',
            'Another unmapped joining error',
        ]);
    });
});
