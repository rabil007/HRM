import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { recentItemsFromPayload } from '@/lib/recent-items';
import type { RecentItem } from '@/lib/recent-items';
import { recentItems } from '@/routes';

export function useRecentItems(open: boolean): RecentItem[] {
    const http = useHttp();
    const [items, setItems] = useState<RecentItem[]>([]);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;

        void http
            .get(recentItems.url())
            .then((data) => {
                if (cancelled) {
                    return;
                }

                setItems(recentItemsFromPayload(data));
            })
            .catch(() => {
                if (!cancelled) {
                    setItems([]);
                }
            });

        return () => {
            cancelled = true;
        };
        // useHttp() returns a new object each render. Depending on it retriggers
        // GET /recent-items until the route 429s and React hits max update depth.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    return items;
}
