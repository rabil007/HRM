import { useEffect, useState } from 'react';
import {
    msUntilNextCompanyMidnight,
    nowInCompanyDate,
} from '@/lib/company-timezone';

const SAFETY_POLL_MS = 60_000;

/**
 * Company-local calendar day (`YYYY-MM-DD`) that refreshes at company midnight
 * and when the tab becomes visible again (sleep/wake, backgrounded tabs).
 * Does not trigger network requests.
 */
export function useCompanyLocalDate(timeZone?: string | null): string {
    const [today, setToday] = useState(() => nowInCompanyDate(timeZone));

    useEffect(() => {
        let cancelled = false;
        let midnightTimer: number | undefined;

        const sync = (): void => {
            if (cancelled) {
                return;
            }

            const next = nowInCompanyDate(timeZone);

            setToday((current) => (current === next ? current : next));
        };

        const scheduleMidnight = (): void => {
            if (midnightTimer !== undefined) {
                window.clearTimeout(midnightTimer);
            }

            const delay = msUntilNextCompanyMidnight(timeZone);

            midnightTimer = window.setTimeout(() => {
                sync();
                scheduleMidnight();
            }, delay + 50);
        };

        sync();
        scheduleMidnight();
        const safetyTimer = window.setInterval(sync, SAFETY_POLL_MS);

        const onVisibility = (): void => {
            if (document.visibilityState === 'visible') {
                sync();
                scheduleMidnight();
            }
        };

        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            cancelled = true;

            if (midnightTimer !== undefined) {
                window.clearTimeout(midnightTimer);
            }

            if (safetyTimer !== undefined) {
                window.clearInterval(safetyTimer);
            }

            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [timeZone]);

    return today;
}
