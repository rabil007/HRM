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
    }, [http, open]);

    return items;
}
