import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    MASTER_DATA_DELETE_BLOCKED_MESSAGE,
    masterDataCanDelete,
    masterDataIsInUse,
    masterDataUsageTooltip,
} from './usage.ts';

describe('master-data usage helpers', () => {
    it('detects in-use records and builds tooltips', () => {
        assert.equal(masterDataIsInUse({ is_in_use: true }), true);
        assert.equal(masterDataIsInUse({ is_in_use: false }), false);

        assert.equal(
            masterDataUsageTooltip({
                is_in_use: true,
                usage_label: 'employees',
            }),
            'Used by employees. Delete is unavailable.',
        );

        assert.equal(
            masterDataUsageTooltip({
                is_in_use: true,
                usage_count: 14,
            }),
            'Used by 14 records. Delete is unavailable.',
        );

        assert.equal(
            masterDataUsageTooltip({ is_in_use: true, usage_count: null }),
            MASTER_DATA_DELETE_BLOCKED_MESSAGE,
        );
    });

    it('requires permission and business deletability for can_delete', () => {
        assert.equal(
            masterDataCanDelete({ is_in_use: false, can_delete: true }, true),
            true,
        );
        assert.equal(
            masterDataCanDelete({ is_in_use: true, can_delete: false }, true),
            false,
        );
        assert.equal(
            masterDataCanDelete({ is_in_use: false, can_delete: true }, false),
            false,
        );
    });
});
