import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    compareAuthorizationRevisions,
    isValidAuthorizationRevision,
    parseAuthorizationRevision,
    resolveInertiaVisitAction,
    resolveSyncedAuthorizationRevision,
    shouldAdvanceAuthorizationRevision,
    shouldReportManualRefreshSuccess,
} from './inertia-visit-outcome.ts';

describe('resolveInertiaVisitAction', () => {
    it('treats success as succeed', () => {
        assert.equal(resolveInertiaVisitAction('success', false), 'succeed');
    });

    it('treats unsettled finish as fail, not success', () => {
        assert.equal(resolveInertiaVisitAction('finish', false), 'fail');
    });

    it('ignores events after settlement', () => {
        assert.equal(resolveInertiaVisitAction('success', true), 'ignore');
        assert.equal(resolveInertiaVisitAction('finish', true), 'ignore');
    });

    it('maps auth HTTP statuses', () => {
        assert.equal(resolveInertiaVisitAction('http_401', false), 'login');
        assert.equal(resolveInertiaVisitAction('http_419', false), 'login');
        assert.equal(resolveInertiaVisitAction('http_403', false), 'forbidden');
    });

    it('maps cancel and error to fail', () => {
        assert.equal(resolveInertiaVisitAction('cancel', false), 'fail');
        assert.equal(resolveInertiaVisitAction('error', false), 'fail');
    });
});

describe('shouldAdvanceAuthorizationRevision', () => {
    it('advances only after succeed', () => {
        assert.equal(shouldAdvanceAuthorizationRevision('succeed'), true);
        assert.equal(shouldAdvanceAuthorizationRevision('fail'), false);
        assert.equal(shouldAdvanceAuthorizationRevision('forbidden'), false);
        assert.equal(shouldAdvanceAuthorizationRevision('login'), false);
        assert.equal(shouldAdvanceAuthorizationRevision('ignore'), false);
    });
});

describe('shouldReportManualRefreshSuccess', () => {
    it('reports success only for succeed', () => {
        assert.equal(shouldReportManualRefreshSuccess('succeed'), true);
        assert.equal(shouldReportManualRefreshSuccess('fail'), false);
        assert.equal(shouldReportManualRefreshSuccess('forbidden'), false);
    });
});

describe('parseAuthorizationRevision', () => {
    it('parses valid user.company tokens', () => {
        assert.deepEqual(parseAuthorizationRevision('1.3'), {
            user: 1,
            company: 3,
        });
        assert.deepEqual(parseAuthorizationRevision('10.12'), {
            user: 10,
            company: 12,
        });
    });

    it('rejects missing, empty, and malformed values', () => {
        assert.equal(parseAuthorizationRevision(null), null);
        assert.equal(parseAuthorizationRevision(undefined), null);
        assert.equal(parseAuthorizationRevision(''), null);
        assert.equal(parseAuthorizationRevision('1'), null);
        assert.equal(parseAuthorizationRevision('1.2.3'), null);
        assert.equal(parseAuthorizationRevision('a.b'), null);
        assert.equal(parseAuthorizationRevision(12), null);
        assert.equal(isValidAuthorizationRevision('1.3'), true);
        assert.equal(isValidAuthorizationRevision('bogus'), false);
    });
});

describe('compareAuthorizationRevisions', () => {
    it('compares user then company numerically', () => {
        assert.ok(
            compareAuthorizationRevisions(
                { user: 2, company: 1 },
                { user: 1, company: 9 },
            ) > 0,
        );
        assert.ok(
            compareAuthorizationRevisions(
                { user: 1, company: 4 },
                { user: 1, company: 3 },
            ) > 0,
        );
        assert.equal(
            compareAuthorizationRevisions(
                { user: 1, company: 3 },
                { user: 1, company: 3 },
            ),
            0,
        );
    });
});

describe('resolveSyncedAuthorizationRevision', () => {
    it('commits a successful response with matching authorization revision', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '2.5',
            }),
            '2.5',
        );
    });

    it('does not invent success when the response revision is missing', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: null,
            }),
            null,
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: undefined,
            }),
            null,
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '',
            }),
            null,
        );
    });

    it('rejects a malformed revision', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: 'not-a-revision',
            }),
            null,
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '2',
            }),
            null,
        );
    });

    it('rejects a stale revision still at the previous local token', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '1.3',
            }),
            null,
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '1.2',
            }),
            null,
        );
    });

    it('rejects a response still behind the polled expected revision', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '1.4',
            }),
            null,
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '2.4',
            }),
            null,
        );
    });

    it('accepts a newer valid revision than expected', () => {
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '3.1',
            }),
            '3.1',
        );
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '2.6',
            }),
            '2.6',
        );
    });

    it('supports retry after a failed sync kept the previous revision', () => {
        const afterFailure = resolveSyncedAuthorizationRevision({
            expectedRevision: '2.5',
            receivedRevision: null,
        });

        assert.equal(afterFailure, null);

        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '2.5',
                receivedRevision: '2.5',
            }),
            '2.5',
        );
    });

    it('handles company-context revision change during synchronization', () => {
        // Company bump after the poll: user stays, company rises.
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '4.3',
                receivedRevision: '4.3',
            }),
            '4.3',
        );

        // Another bump lands before the reload completes — adopt the newer token.
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '4.3',
                receivedRevision: '4.5',
            }),
            '4.5',
        );

        // Company switch can lower the company counter; matching expected still commits.
        assert.equal(
            resolveSyncedAuthorizationRevision({
                expectedRevision: '4.2',
                receivedRevision: '4.2',
            }),
            '4.2',
        );
    });

    it('does not commit after cancel or failure actions', () => {
        assert.equal(shouldAdvanceAuthorizationRevision('fail'), false);
        assert.equal(shouldAdvanceAuthorizationRevision('login'), false);
        assert.equal(shouldAdvanceAuthorizationRevision('forbidden'), false);

        // Even if a revision string were present, visit failure must not commit.
        assert.equal(
            shouldAdvanceAuthorizationRevision(
                resolveInertiaVisitAction('cancel', false),
            ),
            false,
        );
        assert.equal(
            shouldAdvanceAuthorizationRevision(
                resolveInertiaVisitAction('finish', false),
            ),
            false,
        );
    });
});
