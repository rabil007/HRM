import {
    formatDisplayDateTime12hInTimezone,
    toCompanyDateLocal,
} from '../../../../lib/company-timezone.ts';
import { formatDisplayDate } from '../../../../lib/format-date-core.ts';

/**
 * Date-only "Requested" label from an absolute correction timestamp.
 */
export function formatCorrectionRequestedDate(
    requestedAt: string | null | undefined,
    timeZone: string,
    dateFormat?: string | null,
): string {
    if (
        requestedAt === null ||
        requestedAt === undefined ||
        requestedAt === ''
    ) {
        return '—';
    }

    const local = toCompanyDateLocal(requestedAt, timeZone);

    return local ? formatDisplayDate(local, dateFormat) : '—';
}

/**
 * Datetime "Requested at" / "Decided at" in company timezone.
 */
export function formatCorrectionTimestamp(
    value: string | null | undefined,
    timeZone: string,
    dateFormat?: string | null,
): string {
    return formatDisplayDateTime12hInTimezone(value, timeZone, dateFormat);
}
