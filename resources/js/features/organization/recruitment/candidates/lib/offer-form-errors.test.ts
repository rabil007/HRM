import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    offerFieldError,
    offerKnownGeneralError,
    offerUnrenderedErrors,
} from './offer-form-errors.ts';

describe('offer-form-errors', () => {
    it('extracts known general errors like lock_version or offer_status', () => {
        assert.equal(
            offerKnownGeneralError({
                lock_version: 'The record was modified by another user.',
            }),
            'The record was modified by another user.',
        );

        assert.equal(
            offerKnownGeneralError({
                offer_status: 'Only Draft offers can be edited.',
            }),
            'Only Draft offers can be edited.',
        );

        assert.equal(offerKnownGeneralError({}), null);
    });

    it('extracts field errors for known offer fields', () => {
        assert.equal(
            offerFieldError(
                { sent_at: 'The actual sent date cannot be in the future.' },
                'sent_at',
            ),
            'The actual sent date cannot be in the future.',
        );

        assert.equal(
            offerFieldError(
                {
                    accepted_at: [
                        'The acceptance date must be on or after the date the offer was sent.',
                    ],
                },
                'accepted_at',
            ),
            'The acceptance date must be on or after the date the offer was sent.',
        );

        assert.equal(
            offerFieldError(
                {
                    acceptance_document:
                        'The acceptance document must be a file of type: pdf, doc, docx.',
                },
                'acceptance_document',
            ),
            'The acceptance document must be a file of type: pdf, doc, docx.',
        );

        assert.equal(offerFieldError({}, 'salary_amount'), undefined);
    });

    it('collects unrendered errors that do not match field or general keys', () => {
        const unrendered = offerUnrenderedErrors({
            sent_at: 'Sent date error',
            lock_version: 'Lock version error',
            custom_check: 'Custom validation failure',
            another_unmapped: ['Another unmapped error'],
        });

        assert.deepEqual(unrendered, [
            'Custom validation failure',
            'Another unmapped error',
        ]);
    });
});
