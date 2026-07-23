import { Link, useHttp } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type FeedItem = {
    id: number;
    title: string | null;
    preview: string;
    priority: string | null;
    published_at: string | null;
    read_at: string | null;
    url: string;
};

type FeedResponse = {
    unread_count: number;
    items: FeedItem[];
};

export function AnnouncementNotificationBell() {
    const http = useHttp();
    const [unreadCount, setUnreadCount] = useState(0);
    const [items, setItems] = useState<FeedItem[]>([]);

    const loadFeed = () => {
        http.get('/organization/announcements/inbox/feed')
            .then((data) => {
                const payload = data as FeedResponse;
                setUnreadCount(payload.unread_count);
                setItems(payload.items);
            })
            .catch(() => {
                // Ignore feed errors in the header.
            });
    };

    useEffect(() => {
        loadFeed();
        const interval = window.setInterval(loadFeed, 60000);

        return () => window.clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const markRead = (item: FeedItem) => {
        if (item.read_at) {
            return;
        }

        void http.post(`/organization/announcements/inbox/${item.id}/read`);
    };

    return (
        <DropdownMenu
            onOpenChange={(open) => {
                if (open) {
                    loadFeed();
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative">
                    <Bell className="size-5" />
                    {unreadCount > 0 ? (
                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] text-white">
                            {unreadCount > 9 ? '9+' : unreadCount}
                        </span>
                    ) : null}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {items.length === 0 ? (
                    <div className="px-2 py-6 text-center text-sm text-muted-foreground">
                        No announcements yet.
                    </div>
                ) : (
                    items.map((item) => (
                        <DropdownMenuItem key={item.id} asChild>
                            <Link
                                href={item.url}
                                className="flex flex-col items-start gap-1 py-2"
                                onClick={() => markRead(item)}
                            >
                                <span className="font-medium">
                                    {item.title}
                                </span>
                                <span className="line-clamp-2 text-xs text-muted-foreground">
                                    {item.preview}
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
