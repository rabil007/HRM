import { useDateFormat } from '@/lib/format-date';

/**
 * Syncs platform Default Date Format into the pure formatter cache so
 * formatDisplayDate(value) callers respect Application settings.
 */
export function PlatformDateFormatSync(): null {
    useDateFormat();

    return null;
}
