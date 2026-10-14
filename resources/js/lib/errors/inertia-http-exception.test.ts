import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isInertiaErrorPageResponse,
    readHttpExceptionResponse,
} from './inertia-http-exception.ts';

describe('inertia-http-exception', () => {
    it('reads nested inertia response payloads', () => {
        const response = readHttpExceptionResponse({
            detail: {
                response: {
                    status: 403,
                    data: { component: 'errors/403' },
                },
            },
        });

        assert.equal(response?.status, 403);
        assert.equal(isInertiaErrorPageResponse(response), true);
    });

    it('ignores non-error components', () => {
        const response = {
            status: 403,
            data: { component: 'organization/companies' },
        };

        assert.equal(isInertiaErrorPageResponse(response), false);
    });
});
