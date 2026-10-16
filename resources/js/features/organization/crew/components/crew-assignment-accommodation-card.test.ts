import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { CrewAccommodationSummaryItem } from '../types.ts';

async function withViteModule<T>(
    importer: (
        load: (path: string) => Promise<Record<string, unknown>>,
    ) => Promise<T>,
): Promise<T> {
    const vite = await createServer({
        configFile: false,
        plugins: [(await import('@vitejs/plugin-react')).default()],
        resolve: {
            alias: {
                '@': new URL('../../../../', import.meta.url).pathname,
            },
        },
    });

    try {
        return await importer((path) => vite.ssrLoadModule(path));
    } finally {
        await vite.close();
    }
}

const hotelStay = (
    overrides: Partial<CrewAccommodationSummaryItem> = {},
): CrewAccommodationSummaryItem => ({
    id: 3,
    stay_type: 'pre_join',
    stay_type_label: 'Pre-Joining Standby',
    accommodation_status: 'hotel',
    accommodation_status_label: 'Hotel',
    hotel_id: 1,
    hotel_name: 'Hotel 1',
    room_type_id: null,
    room_type_name: null,
    room_type_display: 'Not specified',
    check_in_date: '2026-10-03',
    check_out_date: '2026-10-07',
    is_open: false,
    stay_days: 5,
    stay_days_label: '5 hotel days',
    ...overrides,
});

describe('CrewAssignmentAccommodationCard', () => {
    it('always shows Room Type and Edit for hotel stays when permitted', async () => {
        const html = await withViteModule(async (load) => {
            const { CrewAssignmentAccommodationCard } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-accommodation-card.tsx',
            );

            return renderToString(
                React.createElement(
                    CrewAssignmentAccommodationCard as React.ComponentType<{
                        items: CrewAccommodationSummaryItem[];
                        assignmentId: number;
                        canEdit: boolean;
                        defaultOpen?: boolean;
                    }>,
                    {
                        items: [
                            hotelStay(),
                            hotelStay({
                                id: 4,
                                stay_type: 'post_signoff',
                                stay_type_label: 'Demobilisation Standby',
                                room_type_id: 9,
                                room_type_display: 'Unavailable',
                                check_in_date: '2026-10-15',
                                check_out_date: '2026-10-16',
                                stay_days: 2,
                                stay_days_label: '2 hotel days',
                            }),
                        ],
                        assignmentId: 11,
                        canEdit: true,
                        defaultOpen: true,
                    },
                ),
            );
        });

        assert.ok(html.includes('Room Type'));
        assert.ok(html.includes('Not specified'));
        assert.ok(html.includes('Unavailable'));
        assert.ok(html.includes('data-slot="accommodation-edit-button"'));
        assert.ok(html.includes('Hotel 1'));
    });

    it('hides Edit when override permission is absent', async () => {
        const html = await withViteModule(async (load) => {
            const { CrewAssignmentAccommodationCard } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-accommodation-card.tsx',
            );

            return renderToString(
                React.createElement(
                    CrewAssignmentAccommodationCard as React.ComponentType<{
                        items: CrewAccommodationSummaryItem[];
                        assignmentId: number;
                        canEdit: boolean;
                    }>,
                    {
                        items: [hotelStay()],
                        assignmentId: 11,
                        canEdit: false,
                        defaultOpen: true,
                    },
                ),
            );
        });

        assert.equal(
            html.includes('data-slot="accommodation-edit-button"'),
            false,
        );
        assert.ok(html.includes('Not specified'));
    });
});
