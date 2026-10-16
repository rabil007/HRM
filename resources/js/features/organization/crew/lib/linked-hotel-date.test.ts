import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    applyLinkedHotelDatesOnMovementChange,
    movementCalendarDate,
    setHotelDateLink,
    setManualHotelDate,
    storedHotelDateAutoSynced,
} from './linked-hotel-date.ts';

describe('linked hotel dates', () => {
    const checkInLink = {
        dateKey: 'check_in_date' as const,
        syncedKey: 'check_in_date_auto_synced' as const,
        active: true,
    };

    it('uses the company-local calendar date from the movement timestamp', () => {
        assert.equal(movementCalendarDate('2026-10-20T09:00'), '2026-10-20');
        assert.equal(storedHotelDateAutoSynced(true), true);
        assert.equal(storedHotelDateAutoSynced(false), false);
        assert.equal(storedHotelDateAutoSynced(undefined), false);
    });

    it('updates a linked hotel date and leaves other form fields intact', () => {
        const next = applyLinkedHotelDatesOnMovementChange(
            {
                occurred_at: '2026-10-20T09:00',
                check_in_date: '2026-10-20',
                check_in_date_auto_synced: true,
                remarks: 'keep me',
                vessel_id: 4,
            },
            '2026-10-22T11:30',
            [checkInLink],
        );

        assert.equal(next.occurred_at, '2026-10-22T11:30');
        assert.equal(next.check_in_date, '2026-10-22');
        assert.equal(next.remarks, 'keep me');
        assert.equal(next.vessel_id, 4);
    });

    it('preserves a manual hotel date when the movement date changes', () => {
        const manual = setManualHotelDate(
            {
                occurred_at: '2026-10-20T09:00',
                check_out_date: '2026-10-20',
                check_out_date_auto_synced: true,
                remarks: 'keep me',
            },
            'check_out_date',
            'check_out_date_auto_synced',
            '2026-10-19',
        );

        const next = applyLinkedHotelDatesOnMovementChange(
            manual,
            '2026-10-22T09:00',
            [
                {
                    dateKey: 'check_out_date',
                    syncedKey: 'check_out_date_auto_synced',
                    active: true,
                },
            ],
        );

        assert.equal(next.check_out_date, '2026-10-19');
        assert.equal(next.check_out_date_auto_synced, false);
        assert.equal(next.remarks, 'keep me');
    });

    it('resets the hotel date when same-date synchronization is enabled again', () => {
        const relinked = setHotelDateLink(
            {
                occurred_at: '2026-10-22T09:00',
                check_out_date: '2026-10-19',
                check_out_date_auto_synced: false,
            },
            'check_out_date',
            'check_out_date_auto_synced',
            true,
        );

        assert.equal(relinked.check_out_date, '2026-10-22');
        assert.equal(relinked.check_out_date_auto_synced, true);

        const unlinked = setHotelDateLink(
            relinked,
            'check_out_date',
            'check_out_date_auto_synced',
            false,
        );

        assert.equal(unlinked.check_out_date, '2026-10-22');
        assert.equal(unlinked.check_out_date_auto_synced, false);
    });

    it('does not follow the movement date when the hotel link is inactive', () => {
        const next = applyLinkedHotelDatesOnMovementChange(
            {
                occurred_at: '2026-10-20T09:00',
                check_in_date: '2026-10-20',
                check_in_date_auto_synced: true,
            },
            '2026-10-25T09:00',
            [{ ...checkInLink, active: false }],
        );

        assert.equal(next.check_in_date, '2026-10-20');
    });
});
