import { router } from '@inertiajs/react';
import { dashboard, home, login } from '@/routes';

export function canSafelyNavigateBack(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    if (window.history.length <= 1) {
        return false;
    }

    const referrer = document.referrer;

    if (referrer === '') {
        return true;
    }

    try {
        return new URL(referrer).origin === window.location.origin;
    } catch {
        return false;
    }
}

export function navigateBackWithFallback(fallbackHref: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!canSafelyNavigateBack()) {
        router.visit(fallbackHref);

        return;
    }

    try {
        window.history.back();
    } catch {
        router.visit(fallbackHref);
    }
}

export function safeDashboardHref(isAuthenticated: boolean): string {
    return isAuthenticated ? dashboard.url() : home.url();
}

export function homeHref(): string {
    return home.url();
}

export function loginHref(): string {
    return login.url();
}

/**
 * Reload the current document with GET only. Used for CSRF/session recovery (419).
 * Does not replay Inertia POST/PUT/PATCH/DELETE visits.
 */
export function recoverExpiredSession(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const path = window.location.pathname;

    if (path === login.url() || path.startsWith(`${login.url()}/`)) {
        window.location.assign(login.url());

        return;
    }

    window.location.reload();
}

export function refreshCurrentPage(): void {
    recoverExpiredSession();
}

export function retryCurrentDocument(): void {
    router.visit(window.location.href, {
        preserveScroll: true,
        preserveState: false,
    });
}
