import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    formatCompanyTimezoneLabel,
    formatDisplayDateTime12hInTimezone,
    isCompanyTimeInFuture,
    nowInCompanyDate,
    nowInCompanyTime,
    safeCompanyTimezone,
    toCompanyDateLocal,
    toCompanyDateTimeLocal,
} from './company-timezone.ts';

describe('company-timezone helper', () => {
    describe('safeCompanyTimezone', () => {
        it('returns valid IANA timezones as provided', () => {
            assert.equal(safeCompanyTimezone('Asia/Dubai'), 'Asia/Dubai');
            assert.equal(safeCompanyTimezone('Europe/London'), 'Europe/London');
            assert.equal(
                safeCompanyTimezone('America/New_York'),
                'America/New_York',
            );
            assert.equal(safeCompanyTimezone('Asia/Kolkata'), 'Asia/Kolkata');
        });

        it('falls back to provided fallback or UTC on invalid or missing timezones', () => {
            assert.equal(safeCompanyTimezone(null), 'UTC');
            assert.equal(safeCompanyTimezone(undefined), 'UTC');
            assert.equal(safeCompanyTimezone(''), 'UTC');
            assert.equal(safeCompanyTimezone('Invalid/Zone'), 'UTC');
            assert.equal(
                safeCompanyTimezone('Invalid/Zone', 'Asia/Dubai'),
                'Asia/Dubai',
            );
            assert.equal(
                safeCompanyTimezone('Invalid/Zone', 'Also/Invalid'),
                'UTC',
            );
        });
    });

    describe('formatCompanyTimezoneLabel', () => {
        it('formats clear human-friendly labels with UTC offsets', () => {
            const date = new Date('2026-09-17T12:00:00Z');
            assert.equal(
                formatCompanyTimezoneLabel('Asia/Dubai', date),
                'Gulf Standard Time (UTC+4)',
            );
            assert.equal(
                formatCompanyTimezoneLabel('Asia/Kolkata', date),
                'India Standard Time (UTC+5:30)',
            );
        });

        it('handles Daylight Saving Time correctly across seasons', () => {
            const winter = new Date('2026-01-15T12:00:00Z');
            const summer = new Date('2026-07-15T12:00:00Z');

            // London
            assert.equal(
                formatCompanyTimezoneLabel('Europe/London', winter),
                'Greenwich Mean Time (UTC+0)',
            );
            assert.equal(
                formatCompanyTimezoneLabel('Europe/London', summer),
                'British Summer Time (UTC+1)',
            );

            // New York
            assert.equal(
                formatCompanyTimezoneLabel('America/New_York', winter),
                'Eastern Standard Time (UTC-5)',
            );
            assert.equal(
                formatCompanyTimezoneLabel('America/New_York', summer),
                'Eastern Daylight Time (UTC-4)',
            );
        });
    });

    describe('cross-timezone browser simulation (UTC & Kolkata browsers vs Dubai company)', () => {
        // Instant: 2026-09-17 19:30:00 UTC
        // Device in UTC sees 19:30
        // Device in India (UTC+5:30) sees 01:00 on 18 Sep
        // Company in Dubai (UTC+4) sees 23:30 on 17 Sep
        const testInstant = new Date('2026-09-17T19:30:00Z');

        it('formats the same global instant as Dubai wall-clock regardless of browser environment', () => {
            const dubaiWallClock = nowInCompanyTime('Asia/Dubai', testInstant);
            assert.equal(dubaiWallClock, '2026-09-17T23:30');

            const dubaiDate = nowInCompanyDate('Asia/Dubai', testInstant);
            assert.equal(dubaiDate, '2026-09-17');
        });

        it('formats ISO timestamps with Z to Dubai time identically', () => {
            assert.equal(
                toCompanyDateTimeLocal('2026-09-17T19:30:00Z', 'Asia/Dubai'),
                '2026-09-17T23:30',
            );
            assert.equal(
                toCompanyDateLocal('2026-09-17T19:30:00Z', 'Asia/Dubai'),
                '2026-09-17',
            );
        });

        it('formats ISO timestamps with +00:00 offset identically', () => {
            assert.equal(
                toCompanyDateTimeLocal(
                    '2026-09-17T19:30:00+00:00',
                    'Asia/Dubai',
                ),
                '2026-09-17T23:30',
            );
        });
    });

    describe('midnight boundary scenario', () => {
        // 17 Sep 23:55 Dubai time (19:55 UTC)
        const beforeMidnight = new Date('2026-09-17T19:55:00Z');
        // 18 Sep 00:00 Dubai time (20:00 UTC)
        const exactMidnight = new Date('2026-09-17T20:00:00Z');
        // 18 Sep 00:05 Dubai time (20:05 UTC)
        const afterMidnight = new Date('2026-09-17T20:05:00Z');

        it('preserves calendar date correctly across midnight without shift', () => {
            // Before midnight
            assert.equal(
                nowInCompanyTime('Asia/Dubai', beforeMidnight),
                '2026-09-17T23:55',
            );
            assert.equal(
                nowInCompanyDate('Asia/Dubai', beforeMidnight),
                '2026-09-17',
            );

            // Exact midnight (00:00:00)
            assert.equal(
                nowInCompanyTime('Asia/Dubai', exactMidnight),
                '2026-09-18T00:00',
            );
            assert.equal(
                nowInCompanyDate('Asia/Dubai', exactMidnight),
                '2026-09-18',
            );

            // After midnight
            assert.equal(
                nowInCompanyTime('Asia/Dubai', afterMidnight),
                '2026-09-18T00:05',
            );
            assert.equal(
                nowInCompanyDate('Asia/Dubai', afterMidnight),
                '2026-09-18',
            );
        });

        it('parses timestamps across midnight boundary correctly', () => {
            assert.equal(
                toCompanyDateTimeLocal('2026-09-17T20:05:00Z', 'Asia/Dubai'),
                '2026-09-18T00:05',
            );
            assert.equal(
                toCompanyDateLocal('2026-09-17T20:05:00Z', 'Asia/Dubai'),
                '2026-09-18',
            );
        });
    });

    describe('existing backend timestamp inputs', () => {
        it('preserves existing datetime-local string (YYYY-MM-DDTHH:mm)', () => {
            assert.equal(
                toCompanyDateTimeLocal('2026-09-17T23:30', 'Asia/Dubai'),
                '2026-09-17T23:30',
            );
        });

        it('converts snapshot display string (YYYY-MM-DD HH:mm) into datetime-local', () => {
            assert.equal(
                toCompanyDateTimeLocal('2026-09-17 23:30', 'Asia/Dubai'),
                '2026-09-17T23:30',
            );
        });

        it('converts SQL UTC timestamp (YYYY-MM-DD HH:mm:ss) into company local time', () => {
            assert.equal(
                toCompanyDateTimeLocal('2026-09-17 19:30:00', 'Asia/Dubai'),
                '2026-09-17T23:30',
            );
        });

        it('handles null, undefined, empty, or invalid dates gracefully', () => {
            assert.equal(toCompanyDateTimeLocal(null, 'Asia/Dubai'), '');
            assert.equal(toCompanyDateTimeLocal(undefined, 'Asia/Dubai'), '');
            assert.equal(toCompanyDateTimeLocal('', 'Asia/Dubai'), '');
            assert.equal(
                toCompanyDateTimeLocal('not-a-date', 'Asia/Dubai'),
                '',
            );
        });
    });

    describe('12-hour display formatting in company timezone', () => {
        it('formats ISO timestamp into 12-hour display in company timezone', () => {
            assert.equal(
                formatDisplayDateTime12hInTimezone(
                    '2026-09-17T19:30:00Z',
                    'Asia/Dubai',
                ),
                '17-09-2026 11:30 PM',
            );
        });

        it('formats datetime-local string into 12-hour display without browser shift', () => {
            assert.equal(
                formatDisplayDateTime12hInTimezone(
                    '2026-09-17T23:30',
                    'Asia/Dubai',
                ),
                '17-09-2026 11:30 PM',
            );
            assert.equal(
                formatDisplayDateTime12hInTimezone(
                    '2026-09-18T00:05',
                    'Asia/Dubai',
                ),
                '18-09-2026 12:05 AM',
            );
        });

        it('handles null/empty gracefully', () => {
            assert.equal(
                formatDisplayDateTime12hInTimezone(null, 'Asia/Dubai'),
                '—',
            );
            assert.equal(
                formatDisplayDateTime12hInTimezone('', 'Asia/Dubai'),
                '—',
            );
        });
    });

    describe('future actual comparison in company time', () => {
        // Reference time: 17 Sep 2026, 23:30 Dubai time
        const refInstant = new Date('2026-09-17T19:30:00Z');

        it('identifies past and current times as not future', () => {
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-17T22:00',
                    'Asia/Dubai',
                    refInstant,
                ),
                false,
            );
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-17T23:30',
                    'Asia/Dubai',
                    refInstant,
                ),
                false,
            );
        });

        it('identifies future times in company time as future', () => {
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-17T23:35',
                    'Asia/Dubai',
                    refInstant,
                ),
                true,
            );
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-18T00:05',
                    'Asia/Dubai',
                    refInstant,
                ),
                true,
            );
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-20T10:00',
                    'Asia/Dubai',
                    refInstant,
                ),
                true,
            );
        });

        it('evaluates comparison in company time, not device local time', () => {
            // In a device where local time is 19:30 (UTC), entering 23:00 would look future to the device
            // but is legitimately in the PAST for Dubai (since Dubai is currently 23:30)
            assert.equal(
                isCompanyTimeInFuture(
                    '2026-09-17T23:00',
                    'Asia/Dubai',
                    refInstant,
                ),
                false,
            );
        });
    });
});
