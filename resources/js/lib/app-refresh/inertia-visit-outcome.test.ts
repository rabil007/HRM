import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    resolveInertiaVisitAction,
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
