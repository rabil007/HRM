import type { DocumentShowBackContext } from '../shared/types';

export function documentShowBackQuery(
    back: DocumentShowBackContext,
): Record<string, string> {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.from === 'index' || back.from === 'library') {
        if (back.expiry && back.expiry !== 'all') {
            query.expiry = back.expiry;
        }

        if (back.search?.trim()) {
            query.search = back.search.trim();
        }

        if (back.requirement_status?.trim()) {
            query.requirement_status = back.requirement_status.trim();
        }

        if (back.department_id?.trim()) {
            query.department_id = back.department_id.trim();
        }

        if (back.page && back.page > 1) {
            query.page = String(back.page);
        }
    }

    return query;
}
