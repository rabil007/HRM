import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { validationMessageFromResponse } from './quick-create-post.ts';

describe('quick-create-post validation messages', () => {
    it('extracts the first validation error message from a 422 payload', () => {
        const payload = JSON.stringify({
            message: 'The given data was invalid.',
            errors: {
                name: [
                    'A client with this name already exists but is inactive.',
                ],
            },
        });

        assert.equal(
            validationMessageFromResponse(payload),
            'A client with this name already exists but is inactive.',
        );
    });

    it('falls back to the top-level message when field errors are absent', () => {
        const payload = JSON.stringify({
            message: 'Could not create project.',
        });

        assert.equal(
            validationMessageFromResponse(payload),
            'Could not create project.',
        );
    });
});
