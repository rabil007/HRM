import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    DEFAULT_DATE_FORMAT,
    formatDisplayDate,
    formatDisplayValue,
    isIsoDateString,
    SUPPORTED_DATE_FORMATS,
} from './format-date.ts';

describe('format-date utility', () => {
    describe('formatDisplayDate with supported platform formats', () => {
        const testDate = '2026-09-28';

        it('formats ISO date as Y-m-d (2026-09-28)', () => {
            assert.equal(formatDisplayDate(testDate, 'Y-m-d'), '2026-09-28');
        });

        it('formats ISO date as d/m/Y (28/09/2026)', () => {
            assert.equal(formatDisplayDate(testDate, 'd/m/Y'), '28/09/2026');
        });

        it('formats ISO date as m/d/Y (09/28/2026)', () => {
            assert.equal(formatDisplayDate(testDate, 'm/d/Y'), '09/28/2026');
        });

        it('formats ISO date as d-m-Y (28-09-2026)', () => {
            assert.equal(formatDisplayDate(testDate, 'd-m-Y'), '28-09-2026');
        });

        it('formats ISO date as M d, Y (Sep 28, 2026)', () => {
            assert.equal(formatDisplayDate(testDate, 'M d, Y'), 'Sep 28, 2026');
        });

        it('formats single-digit months correctly for M d, Y', () => {
            assert.equal(
                formatDisplayDate('2026-05-21', 'M d, Y'),
                'May 21, 2026',
            );
            assert.equal(
                formatDisplayDate('2026-01-05', 'M d, Y'),
                'Jan 05, 2026',
            );
        });
    });

    describe('fallbacks and edge cases', () => {
        it('returns em dash for null, undefined, or empty strings', () => {
            assert.equal(formatDisplayDate(null), '—');
            assert.equal(formatDisplayDate(undefined), '—');
            assert.equal(formatDisplayDate(''), '—');
            assert.equal(formatDisplayDate('   '), '—');
        });

        it('defaults to d-m-Y when format is omitted, null, or unknown', () => {
            assert.equal(formatDisplayDate('2026-09-28'), '28-09-2026');
            assert.equal(formatDisplayDate('2026-09-28', null), '28-09-2026');
            assert.equal(
                formatDisplayDate('2026-09-28', undefined),
                '28-09-2026',
            );
            assert.equal(
                formatDisplayDate('2026-09-28', 'unknown-format'),
                '28-09-2026',
            );
            assert.equal(DEFAULT_DATE_FORMAT, 'd-m-Y');
        });

        it('returns non-ISO date strings trimmed without modification', () => {
            assert.equal(formatDisplayDate('Not a date'), 'Not a date');
            assert.equal(formatDisplayDate('28-09-2026'), '28-09-2026');
        });

        it('parses timestamps by extracting the ISO calendar date without timezone shift', () => {
            // Calendar date portion 2026-09-28 should remain day 28 in all formats
            assert.equal(
                formatDisplayDate('2026-09-28T00:00:00Z', 'd-m-Y'),
                '28-09-2026',
            );
            assert.equal(
                formatDisplayDate('2026-09-28T23:59:59Z', 'd-m-Y'),
                '28-09-2026',
            );
            assert.equal(
                formatDisplayDate('2026-09-28T00:00:00+14:00', 'd/m/Y'),
                '28/09/2026',
            );
            assert.equal(
                formatDisplayDate('2026-09-28 14:30:00', 'M d, Y'),
                'Sep 28, 2026',
            );
        });
    });

    describe('isIsoDateString', () => {
        it('identifies ISO date strings starting with YYYY-MM-DD', () => {
            assert.equal(isIsoDateString('2026-09-28'), true);
            assert.equal(isIsoDateString('2026-09-28T12:00:00Z'), true);
            assert.equal(isIsoDateString('28-09-2026'), false);
            assert.equal(isIsoDateString('invalid'), false);
        });
    });

    describe('formatDisplayValue with date formatting', () => {
        it('formats ISO dates using specified format', () => {
            assert.equal(
                formatDisplayValue('2026-09-28', 'd-m-Y'),
                '28-09-2026',
            );
            assert.equal(
                formatDisplayValue('2026-09-28', 'd/m/Y'),
                '28/09/2026',
            );
        });

        it('handles non-date values properly', () => {
            assert.equal(formatDisplayValue(true), 'Yes');
            assert.equal(formatDisplayValue(false), 'No');
            assert.equal(formatDisplayValue(42), '42');
            assert.equal(formatDisplayValue('Regular text'), 'Regular text');
            assert.equal(formatDisplayValue(null), '—');
        });
    });

    describe('supported date format catalog', () => {
        it('contains all 5 platform-supported formats', () => {
            assert.deepEqual(SUPPORTED_DATE_FORMATS, [
                'Y-m-d',
                'd/m/Y',
                'm/d/Y',
                'd-m-Y',
                'M d, Y',
            ]);
        });
    });
});
