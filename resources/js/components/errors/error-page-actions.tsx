import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import type { ErrorPageActionId } from '@/lib/errors/error-page-config';
import {
    homeHref,
    loginHref,
    navigateBackWithFallback,
    recoverExpiredSession,
    retryCurrentDocument,
    safeDashboardHref,
} from '@/lib/errors/error-page-navigation';

type SharedAuth = {
    user?: { id: number } | null;
};

function useIsAuthenticated(): boolean {
    const { auth } = usePage<{ auth?: SharedAuth }>().props;

    return Boolean(auth?.user?.id);
}

export function ErrorPageActions({
    actions,
    retryAfterSeconds = null,
    onRetry,
}: {
    actions: ErrorPageActionId[];
    retryAfterSeconds?: number | null;
    onRetry?: () => void;
}) {
    const isAuthenticated = useIsAuthenticated();
    const dashboardHref = safeDashboardHref(isAuthenticated);
    const [retryCountdown, setRetryCountdown] = useState(() =>
        Math.max(0, retryAfterSeconds ?? 0),
    );

    useEffect(() => {
        if (retryCountdown <= 0) {
            return;
        }

        const timer = window.setTimeout(() => {
            setRetryCountdown((value) => Math.max(0, value - 1));
        }, 1_000);

        return () => window.clearTimeout(timer);
    }, [retryCountdown]);

    const retryDisabled = retryCountdown > 0;

    const runRetry = () => {
        if (retryDisabled) {
            return;
        }

        if (onRetry) {
            onRetry();

            return;
        }

        retryCurrentDocument();
    };

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-center">
            {actions.map((action) => {
                if (action === 'back') {
                    return (
                        <Button
                            key={action}
                            type="button"
                            variant="secondary"
                            className="h-11 rounded-xl px-5"
                            onClick={() =>
                                navigateBackWithFallback(dashboardHref)
                            }
                        >
                            Go back
                        </Button>
                    );
                }

                if (action === 'dashboard') {
                    return (
                        <Button
                            key={action}
                            asChild
                            className="h-11 rounded-xl px-5"
                        >
                            <Link href={dashboardHref}>
                                {isAuthenticated
                                    ? 'Back to dashboard'
                                    : 'Go to home'}
                            </Link>
                        </Button>
                    );
                }

                if (action === 'home') {
                    return (
                        <Button
                            key={action}
                            asChild
                            variant="secondary"
                            className="h-11 rounded-xl px-5"
                        >
                            <Link href={homeHref()}>Go to home</Link>
                        </Button>
                    );
                }

                if (action === 'login') {
                    return (
                        <Button
                            key={action}
                            asChild
                            className="h-11 rounded-xl px-5"
                        >
                            <Link href={loginHref()}>Sign in</Link>
                        </Button>
                    );
                }

                if (action === 'refresh') {
                    return (
                        <Button
                            key={action}
                            type="button"
                            className="h-11 rounded-xl px-5"
                            onClick={() => recoverExpiredSession()}
                        >
                            Refresh page
                        </Button>
                    );
                }

                if (action === 'retry') {
                    return (
                        <Button
                            key={action}
                            type="button"
                            className="h-11 rounded-xl px-5"
                            disabled={retryDisabled}
                            onClick={runRetry}
                        >
                            {retryDisabled
                                ? `Try again in ${retryCountdown}s`
                                : 'Try again'}
                        </Button>
                    );
                }

                return null;
            })}
        </div>
    );
}
