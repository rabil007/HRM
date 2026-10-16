import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import type { InertiaFormProps } from '@inertiajs/react';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { ViteDevServer } from 'vite';
import type { CrewMovementActionFormData } from '../types.ts';
import { applyLinkedHotelDatesOnMovementChange } from './linked-hotel-date.ts';

let vite: ViteDevServer;

function movementForm(
    data: Partial<CrewMovementActionFormData>,
): InertiaFormProps<CrewMovementActionFormData> {
    return {
        data: {
            action: 'record_arrival',
            occurred_at: '2026-10-16T09:00',
            check_in_date: '2026-10-16',
            check_in_date_auto_synced: true,
            remarks: 'keep me',
            ...data,
        },
        errors: {},
        setData: () => undefined,
    } as unknown as InertiaFormProps<CrewMovementActionFormData>;
}

before(async () => {
    vite = await createServer({
        configFile: false,
        plugins: [(await import('@vitejs/plugin-react')).default()],
        resolve: {
            alias: {
                '@': new URL('../../../../', import.meta.url).pathname,
            },
        },
    });
});

after(async () => {
    await vite.close();
});

describe('movement date entry render', () => {
    it('lets a schedulable action pick a future date before scheduling mode is derived from that date', async () => {
        const { MovementOccurredAtField } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/actions/forms/movement-form-shared.tsx',
        );

        const html = renderToString(
            React.createElement(MovementOccurredAtField, {
                form: movementForm({
                    action: 'record_arrival',
                    occurred_at: '2026-10-16T09:00',
                }),
                label: 'Arrival Date & Time',
                timezone: 'Asia/Dubai',
                companyNow: '2026-10-16T12:00',
                allowsFutureDates: true,
                schedulingMode: false,
            }),
        );

        assert.match(html, /type="datetime-local"/);
        assert.match(html, /value="2026-10-16T09:00"/);
        assert.equal(html.includes('max='), false);
        assert.match(html, /Recorded in company time/);
        assert.equal(html.includes('cannot be scheduled'), false);
    });

    it('shows Schedule Movement copy once the selected arrival is in the future', async () => {
        const { MovementOccurredAtField } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/actions/forms/movement-form-shared.tsx',
        );

        const html = renderToString(
            React.createElement(MovementOccurredAtField, {
                form: movementForm({ occurred_at: '2026-10-20T09:00' }),
                label: 'Arrival Date & Time',
                timezone: 'Asia/Dubai',
                companyNow: '2026-10-16T12:00',
                allowsFutureDates: true,
                schedulingMode: true,
            }),
        );

        assert.equal(html.includes('max='), false);
        assert.match(html, /Scheduled in company time/);
        assert.equal(html.includes('cannot be scheduled'), false);
    });

    it('keeps a company-now maximum for nonschedulable actions and a chronology minimum for disembarkation', async () => {
        const { MovementOccurredAtField } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/actions/forms/movement-form-shared.tsx',
        );

        const cancelHtml = renderToString(
            React.createElement(MovementOccurredAtField, {
                form: movementForm({
                    action: 'cancel_assignment',
                    occurred_at: '2026-10-20T09:00',
                }),
                label: 'Cancellation Date & Time',
                timezone: 'Asia/Dubai',
                companyNow: '2026-10-16T12:00',
                allowsFutureDates: false,
                schedulingMode: false,
            }),
        );

        assert.match(cancelHtml, /max="2026-10-16T12:00"/);
        assert.match(cancelHtml, /cannot be scheduled/);

        const disembarkHtml = renderToString(
            React.createElement(MovementOccurredAtField, {
                form: movementForm({
                    action: 'confirm_disembarkation',
                    occurred_at: '2026-10-20T09:00',
                }),
                label: 'Disembarkation Date & Time',
                timezone: 'Asia/Dubai',
                companyNow: '2026-10-16T12:00',
                allowsFutureDates: true,
                min: '2026-10-12T00:00',
            }),
        );

        assert.equal(disembarkHtml.includes('max='), false);
        assert.match(disembarkHtml, /min="2026-10-12T00:00"/);
    });

    it('renders the same-date hotel checkbox and follows or preserves the hotel date', async () => {
        const { LinkedHotelDateField } = await vite.ssrLoadModule(
            './resources/js/features/organization/crew/actions/forms/linked-hotel-date-field.tsx',
        );

        const linkedHtml = renderToString(
            React.createElement(LinkedHotelDateField, {
                id: 'movement-check-in-date',
                label: 'Hotel Check-In',
                checkboxLabel: 'Use Arrival Date for Hotel Check-In',
                value: '2026-10-16',
                linked: true,
                onLinkedChange: () => undefined,
                onValueChange: () => undefined,
            }),
        );

        assert.match(linkedHtml, /Use Arrival Date for Hotel Check-In/);
        assert.match(linkedHtml, /data-state="checked"/);
        assert.match(linkedHtml, /readonly/);
        assert.match(linkedHtml, /value="2026-10-16"/);

        const manualHtml = renderToString(
            React.createElement(LinkedHotelDateField, {
                id: 'movement-check-out-date',
                label: 'Hotel Check-Out',
                checkboxLabel: 'Use Vessel Joining Date for Hotel Check-Out',
                value: '2026-10-19',
                linked: false,
                onLinkedChange: () => undefined,
                onValueChange: () => undefined,
            }),
        );

        const manualInput =
            manualHtml.match(/<input type="date"[^>]*>/)?.[0] ?? '';

        assert.match(manualHtml, /data-state="unchecked"/);
        assert.match(manualInput, /aria-readonly="false"/);
        assert.equal(/(^|\s)readonly(=|\s|>)/.test(manualInput), false);
        assert.match(manualInput, /value="2026-10-19"/);

        const followed = applyLinkedHotelDatesOnMovementChange(
            {
                occurred_at: '2026-10-16T09:00',
                check_in_date: '2026-10-16',
                check_in_date_auto_synced: true,
                remarks: 'keep me',
            },
            '2026-10-20T09:00',
            [
                {
                    dateKey: 'check_in_date',
                    syncedKey: 'check_in_date_auto_synced',
                    active: true,
                },
            ],
        );

        const followedHtml = renderToString(
            React.createElement(LinkedHotelDateField, {
                id: 'movement-check-in-date',
                label: 'Hotel Check-In',
                checkboxLabel: 'Use Arrival Date for Hotel Check-In',
                value: followed.check_in_date ?? '',
                linked: followed.check_in_date_auto_synced === true,
                onLinkedChange: () => undefined,
                onValueChange: () => undefined,
            }),
        );

        assert.equal(followed.remarks, 'keep me');
        assert.match(followedHtml, /value="2026-10-20"/);
        assert.match(followedHtml, /readonly/);
    });
});
