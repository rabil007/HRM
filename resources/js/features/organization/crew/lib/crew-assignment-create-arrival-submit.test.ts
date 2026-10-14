import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ACTUAL_ARRIVAL_REQUIRED_MESSAGE } from './already-arrived-start.ts';
import {
    isCalendarDateAfterCompanyToday,
    resolveAlreadyArrivedSubmitDecision,
    resolveDefaultCreateArrivalDate,
} from './crew-assignment-create-arrival-submit.ts';

const COMPANY_TZ = 'Asia/Dubai';
const REFERENCE = new Date('2026-10-14T10:00:00Z');

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
                '2026-10-01',
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
    });
});
