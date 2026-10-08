import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it, mock } from 'node:test';
import {
    fetchHireDateChangePreview,
    hireDateCalendarValueChanged,
    parseHireDateChangePreviewPayload,
    resolveHireDateChangePreviewFetchResult,
} from './hire-date-change-preview.ts';

describe('hireDateCalendarValueChanged', () => {
    it('returns false when calendar dates match', () => {
        assert.equal(
            hireDateCalendarValueChanged('2026-02-02', '2026-02-02'),
            false,
        );
        assert.equal(hireDateCalendarValueChanged(null, ''), false);
    });

    it('returns true when hire date changes including empty values', () => {
        assert.equal(
            hireDateCalendarValueChanged('2026-02-02', '2026-04-01'),
            true,
        );
        assert.equal(hireDateCalendarValueChanged('2026-02-02', null), true);
        assert.equal(hireDateCalendarValueChanged(null, '2026-04-01'), true);
    });
});

describe('parseHireDateChangePreviewPayload', () => {
    it('accepts a valid preview payload', () => {
        const parsed = parseHireDateChangePreviewPayload({
            requires_acknowledgment: true,
            previous_hire_date: '2026-02-02',
            new_hire_date: '2026-04-01',
            annual_balance_years: [2026, 2027],
        });

        assert.deepEqual(parsed, {
            requires_acknowledgment: true,
            previous_hire_date: '2026-02-02',
            new_hire_date: '2026-04-01',
            annual_balance_years: [2026, 2027],
        });
    });

    it('rejects invalid preview payloads', () => {
        assert.equal(parseHireDateChangePreviewPayload(null), null);
        assert.equal(
            parseHireDateChangePreviewPayload({
                requires_acknowledgment: 'yes',
            }),
            null,
        );
        assert.equal(
            parseHireDateChangePreviewPayload({
                requires_acknowledgment: true,
                annual_balance_years: ['x'],
            }),
            null,
        );
    });
});

describe('resolveHireDateChangePreviewFetchResult', () => {
    it('maps preview outcomes to save flow decisions', () => {
        assert.equal(
            resolveHireDateChangePreviewFetchResult({ status: 'error' }),
            'abort',
        );
        assert.equal(
            resolveHireDateChangePreviewFetchResult({
                status: 'requires_acknowledgment',
                preview: {
                    requires_acknowledgment: true,
                    previous_hire_date: '2026-02-02',
                    new_hire_date: '2026-04-01',
                    annual_balance_years: [2026],
                },
            }),
            'show_warning',
        );
        assert.equal(
            resolveHireDateChangePreviewFetchResult({
                status: 'continue',
                preview: {
                    requires_acknowledgment: false,
                    previous_hire_date: '2026-02-02',
                    new_hire_date: '2026-04-01',
                    annual_balance_years: [],
                },
            }),
            'continue_save',
        );
    });
});

describe('fetchHireDateChangePreview', () => {
    const originalFetch = globalThis.fetch;
    const originalDocument = globalThis.document;

    afterEach(() => {
        globalThis.fetch = originalFetch;
        globalThis.document = originalDocument;
        mock.restoreAll();
    });

    beforeEach(() => {
        globalThis.document = {
            querySelector: () => ({ content: 'test-csrf' }),
        } as Document;
    });

    it('returns requires_acknowledgment when preview succeeds and needs confirmation', async () => {
        globalThis.fetch = mock.fn(async () => ({
            ok: true,
            json: async () => ({
                requires_acknowledgment: true,
                previous_hire_date: '2026-02-02',
                new_hire_date: '2026-04-01',
                annual_balance_years: [2026],
            }),
        })) as typeof fetch;

        const result = await fetchHireDateChangePreview(1, '2026-04-01');

        assert.equal(result.status, 'requires_acknowledgment');
    });

    it('returns continue when preview succeeds without acknowledgment', async () => {
        globalThis.fetch = mock.fn(async () => ({
            ok: true,
            json: async () => ({
                requires_acknowledgment: false,
                previous_hire_date: '2026-02-02',
                new_hire_date: '2026-04-01',
                annual_balance_years: [],
            }),
        })) as typeof fetch;

        const result = await fetchHireDateChangePreview(1, '2026-04-01');

        assert.equal(result.status, 'continue');
    });

    it('returns error on HTTP failure, invalid payload, or network errors', async () => {
        globalThis.fetch = mock.fn(async () => ({
            ok: false,
            status: 500,
        })) as typeof fetch;

        assert.equal(
            (await fetchHireDateChangePreview(1, '2026-04-01')).status,
            'error',
        );

        globalThis.fetch = mock.fn(async () => ({
            ok: true,
            json: async () => ({ requires_acknowledgment: 'yes' }),
        })) as typeof fetch;

        assert.equal(
            (await fetchHireDateChangePreview(1, '2026-04-01')).status,
            'error',
        );

        globalThis.fetch = mock.fn(async () => {
            throw new Error('network');
        }) as typeof fetch;

        assert.equal(
            (await fetchHireDateChangePreview(1, '2026-04-01')).status,
            'error',
        );
    });
});
