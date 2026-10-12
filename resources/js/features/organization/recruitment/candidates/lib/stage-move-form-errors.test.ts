import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    stageMoveFieldError,
    stageMoveKnownGeneralError,
    stageMoveUnrenderedErrors,
} from './stage-move-form-errors.ts';

describe('stage-move-form-errors', () => {
    it('surfaces field and known general errors separately', () => {
        const errors = {
            reason: 'A correction reason of at least 3 characters is required.',
            lock_version: 'This candidate was updated by someone else.',
            interviewer_user_id:
                'The selected interviewer must be an active member of the current company.',
            mystery: 'Unexpected failure',
        };

        assert.match(
            stageMoveFieldError(errors, 'reason') ?? '',
            /correction reason/i,
        );
        assert.match(
            stageMoveFieldError(errors, 'interviewer_user_id') ?? '',
            /interviewer/i,
        );
        assert.match(stageMoveKnownGeneralError(errors) ?? '', /someone else/i);
        assert.deepEqual(stageMoveUnrenderedErrors(errors), [
            'Unexpected failure',
        ]);
    });
});
