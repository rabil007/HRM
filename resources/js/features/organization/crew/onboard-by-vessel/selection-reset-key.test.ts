import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { onboardSelectionResetKey } from './selection-reset-key.ts';

describe('onboard selection reset key', () => {
    it('ignores pagination so selection can persist across pages', () => {
        const pageOne = onboardSelectionResetKey({
            companyId: 7,
            search: 'arief',
            filters: { vessel_id: '3', page: 1 },
        });
        const pageTwo = onboardSelectionResetKey({
            companyId: 7,
            search: 'arief',
            filters: { vessel_id: '3', page: 2 },
        });

        assert.equal(pageOne, pageTwo);
    });

    it('changes when search, filters, or company change', () => {
        const base = onboardSelectionResetKey({
            companyId: 7,
            search: '',
            filters: { vessel_id: '' },
        });

        assert.notEqual(
            base,
            onboardSelectionResetKey({
                companyId: 8,
                search: '',
                filters: { vessel_id: '' },
            }),
        );
        assert.notEqual(
            base,
            onboardSelectionResetKey({
                companyId: 7,
                search: 'chief',
                filters: { vessel_id: '' },
            }),
        );
        assert.notEqual(
            base,
            onboardSelectionResetKey({
                companyId: 7,
                search: '',
                filters: { vessel_id: '9' },
            }),
        );
    });
});
