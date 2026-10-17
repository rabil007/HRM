import { router, usePage } from '@inertiajs/react';
import { Clock, LogOut, UserCheck } from 'lucide-react';
import { useState } from 'react';
import { stop } from '@/actions/App/Http/Controllers/Organization/ImpersonationController';
import { Button } from '@/components/ui/button';

export function ImpersonationBanner() {
    const { auth } = usePage().props;
    const impersonation = auth?.impersonation;
    const [isReturning, setIsReturning] = useState(false);

    if (!impersonation?.is_impersonating) {
        return null;
    }

    const { target_user, original_user, time_remaining_seconds } =
        impersonation;
    const minutesRemaining = Math.max(
        0,
        Math.floor(time_remaining_seconds / 60),
    );

    const handleReturn = () => {
        setIsReturning(true);
        router.post(
            stop.url(),
            {},
            {
                onFinish: () => setIsReturning(false),
            },
        );
    };

    return (
        <aside
            role="status"
            aria-label="Active user impersonation session"
            className="sticky top-0 z-[60] flex flex-wrap items-center justify-between gap-3 border-b border-amber-500/30 bg-amber-500/15 px-4 py-2 text-xs font-medium text-amber-950 backdrop-blur-md sm:text-sm dark:border-amber-400/25 dark:bg-amber-950/70 dark:text-amber-100"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-amber-500/25 text-amber-900 dark:bg-amber-400/25 dark:text-amber-300">
                    <UserCheck className="size-3.5" />
                </span>
                <span>
                    You are logged in as{' '}
                    <strong className="font-semibold text-amber-950 dark:text-amber-50">
                        {target_user.name}
                    </strong>
                    {target_user.role_name ? ` (${target_user.role_name})` : ''}
                </span>
                <span className="text-amber-800/80 dark:text-amber-300/80">
                    &bull; Original account:{' '}
                    <span className="font-medium text-amber-950 dark:text-amber-100">
                        {original_user.name}
                    </span>
                </span>
                {minutesRemaining > 0 && (
                    <span className="hidden items-center gap-1 text-amber-800/70 md:inline-flex dark:text-amber-400/70">
                        <Clock className="size-3" />
                        {minutesRemaining}m remaining
                    </span>
                )}
            </div>
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={isReturning}
                    onClick={handleReturn}
                    className="border-amber-500/40 bg-amber-500/10 text-amber-950 hover:bg-amber-500/20 hover:text-amber-950 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-100 dark:hover:bg-amber-900/50"
                >
                    <LogOut className="size-3.5" />
                    <span>
                        {isReturning ? 'Returning...' : 'Return to My Account'}
                    </span>
                </Button>
            </div>
        </aside>
    );
}
