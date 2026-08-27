import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { documentShowBackQuery } from './document-show-back-query.ts';

describe('document show back query', () => {
    it('keeps overview index filters on from=index', () => {
        assert.deepEqual(
            documentShowBackQuery({
                from: 'index',
                expiry: 'expired',
                search: ' visa ',
                page: 2,
            }),
            {
                from: 'index',
                expiry: 'expired',
                search: 'visa',
                page: '2',
            },
        );
    });

    it('keeps library filters on from=library', () => {
        assert.deepEqual(
            documentShowBackQuery({
                from: 'library',
                expiry: 'expiring_30',
                search: 'passport',
                page: 3,
            }),
            {
                from: 'library',
                expiry: 'expiring_30',
                search: 'passport',
                page: '3',
            },
        );
    });

    it('omits default expiry and first-page state', () => {
        assert.deepEqual(
            documentShowBackQuery({
                from: 'library',
                expiry: 'all',
                search: '  ',
                page: 1,
            }),
            { from: 'library' },
        );
    });

    it('does not attach list filters for profile or employee browse', () => {
        assert.deepEqual(documentShowBackQuery({ from: 'profile' }), {
            from: 'profile',
        });
        assert.deepEqual(documentShowBackQuery({ from: 'employee-browse' }), {
            from: 'employee-browse',
        });
    });
});
