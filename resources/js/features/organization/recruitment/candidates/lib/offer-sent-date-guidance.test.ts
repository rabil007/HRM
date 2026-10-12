import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveOfferSentDateGuidance } from './offer-sent-date-guidance.ts';

describe('resolveOfferSentDateGuidance', () => {
    it('blocks mark sent when offer date is in the future', () => {
        const guidance = resolveOfferSentDateGuidance({
            offerDate: '2099-01-15',
            sentAt: '2099-01-15',
            companyToday: '2026-10-12',
        });

        assert.equal(guidance.canMarkSent, false);
        assert.match(guidance.message ?? '', /dated in the future/);
    });

    it('blocks future actual sent dates', () => {
        const guidance = resolveOfferSentDateGuidance({
            offerDate: '2020-01-01',
            sentAt: '2099-06-01',
            companyToday: '2026-10-12',
        });

        assert.equal(guidance.canMarkSent, false);
        assert.match(guidance.message ?? '', /cannot be in the future/);
    });

    it('blocks sent date before offer date', () => {
        const guidance = resolveOfferSentDateGuidance({
            offerDate: '2024-06-10',
            sentAt: '2024-06-01',
            companyToday: '2026-10-12',
        });

        assert.equal(guidance.canMarkSent, false);
        assert.match(guidance.message ?? '', /before the Offer Date/);
    });

    it('allows a valid historical send and clarifies no email is sent', () => {
        const guidance = resolveOfferSentDateGuidance({
            offerDate: '2020-01-01',
            sentAt: '2020-01-02',
            companyToday: '2026-10-12',
        });

        assert.equal(guidance.canMarkSent, true);
        assert.match(guidance.message ?? '', /does not send an email/);
    });
});
