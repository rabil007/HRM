import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { mapMovementErrorMessage } from './movement-error-message.ts';

describe('mapMovementErrorMessage', () => {
    it('maps active assignment conflicts to a refresh guidance message', () => {
        assert.match(
            mapMovementErrorMessage(
                'Employee already has an active assignment.',
            ),
            /Crew status changed/i,
        );
    });

    it('maps invalid phase errors to refresh guidance', () => {
        assert.match(
            mapMovementErrorMessage('Invalid phase for action.'),
            /refresh the assignment/i,
        );
    });

    it('returns the original message when no mapping applies', () => {
        assert.equal(
            mapMovementErrorMessage('Destination vessel is required.'),
            'Destination vessel is required.',
        );
    });
});
