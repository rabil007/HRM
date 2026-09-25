import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    ARRIVAL_AFTER_JOIN_MESSAGE,
    SIGNOFF_BEFORE_JOIN_MESSAGE,
    arrivalAfterJoinMessage,
    dependentCreateDateErrorKeys,
    resolveArrivalDateDisplayError,
    resolveSignoffDateDisplayError,
    signoffBeforeJoinMessage,
} from './crew-assignment-create-date-validation.ts';

describe('arrivalAfterJoinMessage', () => {
    it('shows no error when arrival is set and join is blank', () => {
        assert.equal(arrivalAfterJoinMessage('2026-09-27', ''), null);
        assert.equal(arrivalAfterJoinMessage('2026-09-27', null), null);
    });

    it('shows no error when arrival is blank and join is set', () => {
        assert.equal(arrivalAfterJoinMessage('', '2026-09-30'), null);
        assert.equal(arrivalAfterJoinMessage(null, '2026-09-30'), null);
    });

    it('shows no error when arrival is before join', () => {
        assert.equal(arrivalAfterJoinMessage('2026-09-27', '2026-09-30'), null);
    });

    it('shows the order error when arrival is after join', () => {
        assert.equal(
            arrivalAfterJoinMessage('2026-09-30', '2026-09-27'),
            ARRIVAL_AFTER_JOIN_MESSAGE,
        );
    });
});

describe('resolveArrivalDateDisplayError', () => {
    it('clears a stale server arrival-after-join error when join becomes valid', () => {
        assert.equal(
            resolveArrivalDateDisplayError({
                serverError: ARRIVAL_AFTER_JOIN_MESSAGE,
                arrival: '2026-09-30',
                join: '2026-10-01',
            }),
            undefined,
        );
    });

    it('clears a stale server arrival-after-join error when join is cleared', () => {
        assert.equal(
            resolveArrivalDateDisplayError({
                serverError: ARRIVAL_AFTER_JOIN_MESSAGE,
                arrival: '2026-09-30',
                join: '',
            }),
            undefined,
        );
    });

    it('clears a stale server arrival-after-join error when arrival becomes valid', () => {
        assert.equal(
            resolveArrivalDateDisplayError({
                serverError: ARRIVAL_AFTER_JOIN_MESSAGE,
                arrival: '2026-09-26',
                join: '2026-09-27',
            }),
            undefined,
        );
    });

    it('keeps live order errors without requiring resubmit', () => {
        assert.equal(
            resolveArrivalDateDisplayError({
                serverError: undefined,
                arrival: '2026-09-30',
                join: '2026-09-27',
            }),
            ARRIVAL_AFTER_JOIN_MESSAGE,
        );
    });

    it('preserves unrelated arrival server errors', () => {
        assert.equal(
            resolveArrivalDateDisplayError({
                serverError: 'Arrival Date is invalid.',
                arrival: '2026-09-27',
                join: '',
            }),
            'Arrival Date is invalid.',
        );
    });
});

describe('signoffBeforeJoinMessage / resolveSignoffDateDisplayError', () => {
    it('shows no error when either join or sign-off is blank', () => {
        assert.equal(signoffBeforeJoinMessage('', '2026-10-01'), null);
        assert.equal(signoffBeforeJoinMessage('2026-09-30', ''), null);
    });

    it('shows the order error when sign-off is before join', () => {
        assert.equal(
            signoffBeforeJoinMessage('2026-09-30', '2026-09-27'),
            SIGNOFF_BEFORE_JOIN_MESSAGE,
        );
    });

    it('clears a stale sign-off-before-join server error when dates become valid', () => {
        assert.equal(
            resolveSignoffDateDisplayError({
                serverError: SIGNOFF_BEFORE_JOIN_MESSAGE,
                join: '2026-09-27',
                signoff: '2026-10-01',
            }),
            undefined,
        );
    });

    it('clears a stale sign-off-before-join server error when join is cleared', () => {
        assert.equal(
            resolveSignoffDateDisplayError({
                serverError: SIGNOFF_BEFORE_JOIN_MESSAGE,
                join: '',
                signoff: '2026-09-27',
            }),
            undefined,
        );
    });

    it('preserves unrelated sign-off server errors such as required-for-planned', () => {
        assert.equal(
            resolveSignoffDateDisplayError({
                serverError:
                    'Expected Sign-off is required when saving as Planned.',
                join: '2026-09-30',
                signoff: '',
            }),
            'Expected Sign-off is required when saving as Planned.',
        );
    });
});

describe('dependentCreateDateErrorKeys', () => {
    it('clears only arrival keys when arrival changes', () => {
        assert.deepEqual(
            dependentCreateDateErrorKeys('planned_arrival_at', {
                crewIndex: 0,
            }),
            ['planned_arrival_at', 'crew.0.planned_arrival_at'],
        );
    });

    it('clears join-dependent order keys including crew arrival rows', () => {
        assert.deepEqual(
            dependentCreateDateErrorKeys('planned_join_at', {
                crewRowCount: 2,
            }),
            [
                'planned_join_at',
                'planned_arrival_at',
                'planned_signoff_at',
                'crew.0.planned_arrival_at',
                'crew.1.planned_arrival_at',
            ],
        );
    });

    it('clears only sign-off keys when sign-off changes', () => {
        assert.deepEqual(dependentCreateDateErrorKeys('planned_signoff_at'), [
            'planned_signoff_at',
        ]);
    });
});
