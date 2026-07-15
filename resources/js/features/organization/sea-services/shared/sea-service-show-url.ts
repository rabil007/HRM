import { show as seaServiceShow } from '@/routes/organization/sea-services';
import type { SeaServiceShowBackContext } from '@/features/organization/sea-services/types';

export function buildSeaServiceShowUrl(
    seaServiceId: number,
    back: SeaServiceShowBackContext = { from: 'index' },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.from === 'index') {
        if (back.search?.trim()) {
            query.search = back.search.trim();
        }

        if (back.vessel_id?.trim()) {
            query.vessel_id = back.vessel_id.trim();
        }

        if (back.vessel_type_id?.trim()) {
            query.vessel_type_id = back.vessel_type_id.trim();
        }

        if (back.rank_id?.trim()) {
            query.rank_id = back.rank_id.trim();
        }

        if (back.client_id?.trim()) {
            query.client_id = back.client_id.trim();
        }

        if (back.offshore?.trim()) {
            query.offshore = back.offshore.trim();
        }

        if (back.active?.trim()) {
            query.active = back.active.trim();
        }

        if (back.start_date?.trim()) {
            query.start_date = back.start_date.trim();
        }

        if (back.end_date?.trim()) {
            query.end_date = back.end_date.trim();
        }

        if (back.branch_id?.trim()) {
            query.branch_id = back.branch_id.trim();
        }

        if (back.department_id?.trim()) {
            query.department_id = back.department_id.trim();
        }

        if (back.page && back.page > 1) {
            query.page = String(back.page);
        }
    }

    return seaServiceShow.url(seaServiceId, { query });
}

export type { SeaServiceShowBackContext };
