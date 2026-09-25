import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { payrollIndexShowPayment } from './payroll-index-show-payment.ts';

describe('payrollIndexShowPayment', () => {
    it('hides Payment UI for non-financial crew ops users', () => {
        assert.equal(payrollIndexShowPayment(false), false);
    });

    it('shows Payment UI for financial payroll users', () => {
        assert.equal(payrollIndexShowPayment(true), true);
    });

    it('defaults to showing Payment when the flag is omitted (legacy financial pages)', () => {
        assert.equal(payrollIndexShowPayment(undefined), true);
    });
});
