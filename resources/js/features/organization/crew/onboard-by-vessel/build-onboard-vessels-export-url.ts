import { buildListExportUrl } from '@/lib/build-list-export-url';
import { exportMethod as exportOnboardVessels } from '@/routes/organization/crew-assignments/onboard-vessels';

export type OnboardExportScope = 'all' | 'selected';

export function buildOnboardVesselsExportUrl(
    query: Record<string, string | number | boolean | null | undefined>,
    scope: OnboardExportScope,
    assignmentIds: number[] = [],
): string {
    const url = buildListExportUrl(exportOnboardVessels.url(), {
        ...query,
        format: 'xlsx',
        scope,
    });

    if (scope !== 'selected' || assignmentIds.length === 0) {
        return url;
    }

    const params = new URLSearchParams();

    assignmentIds.forEach((id) => {
        params.append('assignment_ids[]', String(id));
    });

    return `${url}${url.includes('?') ? '&' : '?'}${params.toString()}`;
}
