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
import { WebPushNotificationControl } from '@/components/web-push-notification-control';
import { cn } from '@/lib/utils';

type FeedItem = {
    id: string;
    source: 'announcement' | 'crew_operational_alert';
    title: string | null;
    summary: string;
    severity: string | null;
    created_at: string | null;
    read_at: string | null;
    is_read: boolean;
    url: string | null;
    source_label: string;
};

type FeedResponse = {
    unread_count: number;
    items: FeedItem[];
};

function severityLabel(severity: string | null): string | null {
    if (!severity) {
        return null;
    }

    if (severity === 'critical') {
        return 'Critical';
    }

    if (severity === 'warning') {
        return 'Warning';
    }

    if (severity === 'info') {
        return 'Info';
    }

    return severity;
}

function relativeTime(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    const diffMs = Date.now() - date.getTime();

    if (Number.isNaN(diffMs) || diffMs < 0) {
        return null;
    }

    const minutes = Math.floor(diffMs / 60000);

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours} hr ago`;
    }

    const days = Math.floor(hours / 24);

    return `${days}d ago`;
}

export function AnnouncementNotificationBell() {
    const http = useHttp();
    const [unreadCount, setUnreadCount] = useState(0);
    const [items, setItems] = useState<FeedItem[]>([]);

    const loadFeed = () => {
        http.get('/notifications/feed')
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
        if (item.is_read) {
            return;
        }

        if (item.source === 'announcement') {
            const recipientId = item.id.replace('announcement:', '');
            void http.post(
                `/organization/announcements/inbox/${recipientId}/read`,
            );
        } else {
            const recipientId = item.id.replace('crew_operational_alert:', '');
            void http.post(
                `/organization/notifications/crew/${recipientId}/read`,
            );
        }

        setItems((current) =>
            current.map((entry) =>
                entry.id === item.id
                    ? {
                          ...entry,
                          is_read: true,
                          read_at: new Date().toISOString(),
                      }
                    : entry,
            ),
        );
        setUnreadCount((count) => Math.max(0, count - 1));
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
            <DropdownMenuContent align="end" className="w-96">
                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <WebPushNotificationControl />
                <DropdownMenuSeparator />
                {items.length === 0 ? (
                    <div className="px-2 py-6 text-center text-sm text-muted-foreground">
                        No notifications yet.
                    </div>
                ) : (
                    items.map((item) => {
                        const severity = severityLabel(item.severity);
                        const when = relativeTime(item.created_at);
                        const content = (
                            <div
                                className={cn(
                                    'flex w-full flex-col items-start gap-1 py-2',
                                    !item.is_read && 'font-medium',
                                )}
                            >
                                <div className="flex w-full items-start justify-between gap-2">
                                    <span className="line-clamp-1 text-sm">
                                        {severity ? (
                                            <span className="mr-1 text-xs text-muted-foreground">
                                                [{severity}]
                                            </span>
                                        ) : null}
                                        {item.title}
                                    </span>
                                </div>
                                <span className="line-clamp-2 text-xs text-muted-foreground">
                                    {item.summary}
                                </span>
                                <div className="flex w-full items-center justify-between gap-2 text-[11px] text-muted-foreground">
                                    <span>{item.source_label}</span>
                                    {when ? <span>{when}</span> : null}
                                </div>
                            </div>
                        );

                        if (!item.url) {
                            return (
                                <DropdownMenuItem
                                    key={item.id}
                                    className="cursor-pointer"
                                    onSelect={(event) => {
                                        event.preventDefault();
                                        markRead(item);
                                    }}
                                >
                                    {content}
                                </DropdownMenuItem>
                            );
                        }

                        return (
                            <DropdownMenuItem key={item.id} asChild>
                                <Link
                                    href={item.url}
                                    className="flex flex-col items-start gap-1 py-2"
                                    onClick={() => markRead(item)}
                                >
                                    {content}
                                </Link>
                            </DropdownMenuItem>
                        );
                    })
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
