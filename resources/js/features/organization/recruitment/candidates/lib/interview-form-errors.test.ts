import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    interviewFieldError,
    interviewKnownGeneralError,
    interviewUnrenderedErrors,
} from './interview-form-errors.ts';

describe('interview form errors', () => {
    it('exposes field errors for external interviewer, location, and feedback', () => {
        const errors = {
            external_interviewer_name:
                'The external interviewer name field must not be greater than 200 characters.',
            interview_location:
                'The interview location field must not be greater than 300 characters.',
            interview_feedback:
                'The interview feedback field must not be greater than 10000 characters.',
        };

        assert.match(
            interviewFieldError(errors, 'external_interviewer_name') ?? '',
            /200/,
        );
        assert.match(
            interviewFieldError(errors, 'interview_location') ?? '',
            /300/,
        );
        assert.match(
            interviewFieldError(errors, 'interview_feedback') ?? '',
            /10000/,
        );
        assert.deepEqual(interviewUnrenderedErrors(errors), []);
    });

    it('surfaces known general errors and leftover unrendered keys separately', () => {
        const errors = {
            lock_version: 'This candidate was updated by someone else.',
            mystery_key: 'Unexpected server error.',
            interview_mode: 'Invalid mode.',
        };

        assert.equal(
            interviewKnownGeneralError(errors),
            'This candidate was updated by someone else.',
        );
        assert.deepEqual(interviewUnrenderedErrors(errors), [
            'Unexpected server error.',
        ]);
        assert.equal(
            interviewFieldError(errors, 'interview_mode'),
            'Invalid mode.',
        );
    });
});
