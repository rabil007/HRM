import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ACTUAL_ARRIVAL_REQUIRED_MESSAGE } from './already-arrived-start.ts';
import { resolveSingleSubmitRequest } from './crew-assignment-create-actions.ts';
import {
    isCalendarDateAfterCompanyToday,
    resolveAlreadyArrivedSubmitDecision,
    resolveDefaultCreateArrivalDate,
    resolveInitialRowPlannedArrival,
    shouldDefaultSingleCreateArrivalDate,
} from './crew-assignment-create-arrival-submit.ts';

const COMPANY_TZ = 'Asia/Dubai';
const REFERENCE = new Date('2026-10-14T10:00:00Z');

describe('shouldDefaultSingleCreateArrivalDate', () => {
    it('allows defaults only for normal single-create entry', () => {
        assert.equal(
            shouldDefaultSingleCreateArrivalDate({
                fromPlanning: false,
                initialRowCount: 1,
                hasRestoredBulkSession: false,
            }),
            true,
        );
        assert.equal(
            shouldDefaultSingleCreateArrivalDate({
                fromPlanning: false,
                initialRowCount: 2,
                hasRestoredBulkSession: false,
            }),
            false,
        );
        assert.equal(
            shouldDefaultSingleCreateArrivalDate({
                fromPlanning: true,
                initialRowCount: 1,
                hasRestoredBulkSession: false,
            }),
            false,
        );
        assert.equal(
            shouldDefaultSingleCreateArrivalDate({
                fromPlanning: false,
                initialRowCount: 1,
                hasRestoredBulkSession: true,
            }),
            false,
        );
    });
});

describe('resolveInitialRowPlannedArrival', () => {
    it('applies today only to the first row when defaults are enabled', () => {
        assert.equal(
            resolveInitialRowPlannedArrival(0, null, '2026-10-14', true),
            '2026-10-14',
        );
        assert.equal(
            resolveInitialRowPlannedArrival(1, null, '2026-10-14', true),
            null,
        );
        assert.equal(
            resolveInitialRowPlannedArrival(
                0,
                '2026-10-20',
                '2026-10-14',
                true,
            ),
            '2026-10-20',
        );
        assert.equal(
            resolveInitialRowPlannedArrival(0, null, '2026-10-14', false),
            null,
        );
    });
});

describe('resolveDefaultCreateArrivalDate', () => {
    it('prefers planning and restored arrivals over today', () => {
        assert.equal(
            resolveDefaultCreateArrivalDate({
                companyTimezone: COMPANY_TZ,
                planningArrival: '2026-11-01',
                referenceDate: REFERENCE,
            }),
            '2026-11-01',
        );
        assert.equal(
            resolveDefaultCreateArrivalDate({
                companyTimezone: COMPANY_TZ,
                restoredArrival: '2026-10-20',
                referenceDate: REFERENCE,
            }),
            '2026-10-20',
        );
    });

    it('defaults to company-local today when no prefill exists', () => {
        assert.equal(
            resolveDefaultCreateArrivalDate({
                companyTimezone: COMPANY_TZ,
                referenceDate: REFERENCE,
            }),
            '2026-10-14',
        );
    });
});

describe('resolveAlreadyArrivedSubmitDecision', () => {
    it('requires an arrival date before submitting', () => {
        const decision = resolveAlreadyArrivedSubmitDecision(
            '',
            COMPANY_TZ,
            REFERENCE,
        );

        assert.deepEqual(decision, {
            type: 'client_error',
            message: ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
        });
    });

    it('accepts today and past dates for Already Arrived', () => {
        assert.deepEqual(
            resolveAlreadyArrivedSubmitDecision(
                '2026-10-14',
                COMPANY_TZ,
                REFERENCE,
            ),
            { type: 'submit' },
        );
        assert.deepEqual(
            resolveAlreadyArrivedSubmitDecision(
                '2026-10-13',
                COMPANY_TZ,
                REFERENCE,
            ),
            { type: 'submit' },
        );
    });

    it('suggests pre-mobilisation confirmation for future dates', () => {
        assert.deepEqual(
            resolveAlreadyArrivedSubmitDecision(
                '2026-10-17',
                COMPANY_TZ,
                REFERENCE,
            ),
            {
                type: 'confirm_pre_mobilisation',
                plannedArrivalDate: '2026-10-17',
            },
        );
    });

    it('rejects malformed and impossible calendar dates before future comparison', () => {
        for (const invalid of ['2026-02-30', 'invalid text', '2026-13-01']) {
            assert.deepEqual(
                resolveAlreadyArrivedSubmitDecision(
                    invalid,
                    COMPANY_TZ,
                    REFERENCE,
                ),
                {
                    type: 'client_error',
                    message: ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
                },
            );
        }
    });
});

describe('isCalendarDateAfterCompanyToday', () => {
    it('compares calendar dates in the company timezone', () => {
        assert.equal(
            isCalendarDateAfterCompanyToday(
                '2026-10-15',
                COMPANY_TZ,
                REFERENCE,
            ),
            true,
        );
        assert.equal(
            isCalendarDateAfterCompanyToday(
                '2026-10-14',
                COMPANY_TZ,
                REFERENCE,
            ),
            false,
        );
        assert.equal(
            isCalendarDateAfterCompanyToday(
                '2026-02-30',
                COMPANY_TZ,
                REFERENCE,
            ),
            false,
        );
    });
});

describe('pre-mobilisation confirmation payload', () => {
    it('maps confirmation to the existing start path without actual arrival', () => {
        assert.deepEqual(resolveSingleSubmitRequest('pre_mobilisation'), {
            submissionIntent: 'start',
            alreadyArrived: false,
        });
    });
});
