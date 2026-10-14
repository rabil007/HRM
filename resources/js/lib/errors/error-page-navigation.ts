import { router } from '@inertiajs/react';
import { dashboard, home, login } from '@/routes';

export function navigateBackWithFallback(fallbackHref: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.history.length > 1) {
        try {
            window.history.back();

            return;
        } catch {
            // Fall through to safe navigation.
        }
    }

    router.visit(fallbackHref);
}

export function safeDashboardHref(isAuthenticated: boolean): string {
    return isAuthenticated ? dashboard.url() : home.url();
}

export function loginHref(): string {
    return login.url();
}

export function refreshCurrentPage(): void {
    router.reload();
}

export function retryCurrentDocument(): void {
    router.visit(window.location.href, {
        preserveScroll: true,
        preserveState: false,
    });
}
