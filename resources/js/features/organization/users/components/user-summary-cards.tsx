import { Mail } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import type { UserDirectorySummary } from '@/features/organization/users/types';
import { cn } from '@/lib/utils';

type PresenceQuickFilter = '' | 'online' | 'never';

const PRESENCE_ITEMS: {
    key: keyof Pick<UserDirectorySummary, 'total' | 'online' | 'never'>;
    presence: PresenceQuickFilter;
    label: string;
    ariaLabel: string;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}[] = [
    {
        key: 'total',
        presence: '',
        label: 'Total Users',
        ariaLabel: 'Show all users',
        cardClass:
            'border-border hover:border-border dark:border-white/5 dark:hover:border-white/10',
        activeClass:
            'border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10',
        valueClass: 'text-foreground',
    },
    {
        key: 'online',
        presence: 'online',
        label: 'Online Now',
        ariaLabel: 'Show users who are online now',
        cardClass:
            'border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30',
        activeClass: 'border-emerald-500/40 ring-1 ring-emerald-500/25',
        valueClass: 'text-emerald-600 dark:text-emerald-400',
    },
    {
        key: 'never',
        presence: 'never',
        label: 'Never Logged In',
        ariaLabel: 'Show users who have never logged in',
        cardClass:
            'border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30',
        activeClass: 'border-amber-500/40 ring-1 ring-amber-500/25',
        valueClass: 'text-amber-600 dark:text-amber-500',
    },
];

export function UserSummaryCards({
    summary,
    activePresence,
    onSelectPresence,
}: {
    summary: UserDirectorySummary;
    activePresence: string;
    onSelectPresence: (presence: PresenceQuickFilter) => void;
}) {
    const pendingInvites = summary.pending_invites;
    const canRevealInvitations = pendingInvites > 0;

    return (
        <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {PRESENCE_ITEMS.map((item) => {
                const isActive = item.presence === activePresence;

                return (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onSelectPresence(item.presence)}
                        aria-pressed={isActive}
                        aria-label={item.ariaLabel}
                        className="h-full w-full cursor-pointer rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <Card
                            className={cn(
                                'h-full glass-card transition-all duration-200',
                                item.cardClass,
                                isActive && item.activeClass,
                            )}
                        >
                            <CardContent className="p-5">
                                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                    {item.label}
                                </p>
                                <p
                                    className={cn(
                                        'mt-2 text-3xl font-bold tracking-tight tabular-nums',
                                        item.valueClass,
                                    )}
                                >
                                    {summary[item.key]}
                                </p>
                            </CardContent>
                        </Card>
                    </button>
                );
            })}

            {canRevealInvitations ? (
                <a
                    href="#pending-invitations"
                    aria-label={`Show ${pendingInvites} pending invitations`}
                    className="block h-full w-full cursor-pointer rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                >
                    <Card className="h-full glass-card border-violet-500/15 bg-violet-500/[0.04] transition-all duration-200 hover:border-violet-500/30">
                        <CardContent className="p-5">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                    Pending Invitations
                                </p>
                                <Mail
                                    className="size-4 shrink-0 text-violet-500/60"
                                    aria-hidden
                                />
                            </div>
                            <p className="mt-2 text-3xl font-bold tracking-tight text-violet-500 tabular-nums">
                                {pendingInvites}
                            </p>
                        </CardContent>
                    </Card>
                </a>
            ) : (
                <Card className="h-full glass-card border-border dark:border-white/5">
                    <CardContent className="p-5">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                                Pending Invitations
                            </p>
                            <Mail
                                className="size-4 shrink-0 text-muted-foreground/50"
                                aria-hidden
                            />
                        </div>
                        <p className="mt-2 text-3xl font-bold tracking-tight text-foreground tabular-nums">
                            {pendingInvites}
                        </p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
