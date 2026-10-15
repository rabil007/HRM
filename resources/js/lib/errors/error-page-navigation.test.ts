import assert from 'node:assert/strict';
import { register } from 'node:module';
import { after, beforeEach, describe, it } from 'node:test';

type Visit = {
    href: string;
    options?: { preserveScroll?: boolean; preserveState?: boolean };
};

const visits: Visit[] = [];

(globalThis as typeof globalThis & { __omsNavVisits: Visit[] }).__omsNavVisits =
    visits;

const inertiaSource = `export const router = {
    visit: (href, options) => globalThis.__omsNavVisits.push({ href, options }),
};`;

const routesSource = `export const dashboard = { url: () => '/dashboard' };
export const home = { url: () => '/' };
export const login = { url: () => '/login' };`;

const inertiaUrl = JSON.stringify(
    `data:text/javascript,${encodeURIComponent(inertiaSource)}`,
);
const routesUrl = JSON.stringify(
    `data:text/javascript,${encodeURIComponent(routesSource)}`,
);

const hookSource = `
export async function resolve(specifier, context, nextResolve) {
    if (specifier === '@inertiajs/react') {
        return { url: ${inertiaUrl}, shortCircuit: true };
    }

    if (specifier === '@/routes') {
        return { url: ${routesUrl}, shortCircuit: true };
    }

    return nextResolve(specifier, context);
}
`;

await register(`data:text/javascript,${encodeURIComponent(hookSource)}`);

const {
    canSafelyNavigateBack,
    navigateBackWithFallback,
    recoverExpiredSession,
    retryCurrentDocument,
    safeDashboardHref,
} = await import('./error-page-navigation.ts');

const browser = {
    backThrows: false,
    backs: 0,
    reloads: 0,
    assigns: [] as string[],
};

function installBrowser(options: {
    historyLength?: number;
    referrer?: string;
    pathname?: string;
    href?: string;
}): void {
    browser.backs = 0;
    browser.reloads = 0;
    browser.assigns = [];

    const origin = 'https://oms.test';

    Object.defineProperty(globalThis, 'window', {
        configurable: true,
        value: {
            history: {
                length: options.historyLength ?? 2,
                back() {
                    browser.backs += 1;

                    if (browser.backThrows) {
                        throw new Error('history.back failed');
                    }
                },
            },
            location: {
                origin,
                href: options.href ?? `${origin}/crew`,
                pathname: options.pathname ?? '/crew',
                assign(url: string) {
                    browser.assigns.push(url);
                },
                reload() {
                    browser.reloads += 1;
                },
            },
        },
    });

    Object.defineProperty(globalThis, 'document', {
        configurable: true,
        value: { referrer: options.referrer ?? '' },
    });
}

beforeEach(() => {
    visits.length = 0;
    browser.backThrows = false;
    installBrowser({});
});

after(() => {
    delete (globalThis as { window?: unknown }).window;
    delete (globalThis as { document?: unknown }).document;
    delete (globalThis as typeof globalThis & { __omsNavVisits?: Visit[] })
        .__omsNavVisits;
});

describe('error page back navigation', () => {
    it('refuses history.back without a browser history entry', () => {
        delete (globalThis as { window?: unknown }).window;

        assert.equal(canSafelyNavigateBack(), false);

        installBrowser({ historyLength: 1, referrer: 'https://oms.test/crew' });

        assert.equal(canSafelyNavigateBack(), false);
    });

    it('allows back only for an empty or same-origin referrer', () => {
        installBrowser({ referrer: '' });
        assert.equal(canSafelyNavigateBack(), true);

        installBrowser({ referrer: 'https://oms.test/assignments' });
        assert.equal(canSafelyNavigateBack(), true);

        installBrowser({ referrer: 'https://evil.example/phish' });
        assert.equal(canSafelyNavigateBack(), false);

        installBrowser({ referrer: '::::' });
        assert.equal(canSafelyNavigateBack(), false);
    });

    it('uses the fallback when back is unsafe or the history api throws', () => {
        installBrowser({
            historyLength: 1,
            referrer: 'https://evil.example/phish',
        });

        navigateBackWithFallback('/dashboard');

        assert.equal(browser.backs, 0);
        assert.deepEqual(visits, [{ href: '/dashboard', options: undefined }]);

        visits.length = 0;
        installBrowser({ referrer: 'https://oms.test/assignments' });
        navigateBackWithFallback('/dashboard');

        assert.equal(browser.backs, 1);
        assert.deepEqual(visits, []);

        visits.length = 0;
        browser.backThrows = true;
        installBrowser({ referrer: 'https://oms.test/assignments' });
        browser.backThrows = true;
        navigateBackWithFallback('/dashboard');

        assert.equal(browser.backs, 1);
        assert.deepEqual(visits, [{ href: '/dashboard', options: undefined }]);
    });

    it('sends authenticated users to the dashboard and guests home', () => {
        assert.equal(safeDashboardHref(true), '/dashboard');
        assert.equal(safeDashboardHref(false), '/');
    });
});

describe('expired session recovery', () => {
    it('reloads the current document with get and does not replay an inertia visit', () => {
        installBrowser({ pathname: '/organization/crew-assignments/create' });

        recoverExpiredSession();

        assert.equal(browser.reloads, 1);
        assert.deepEqual(browser.assigns, []);
        assert.deepEqual(visits, []);
    });

    it('sends an already-open login url to login instead of reloading', () => {
        installBrowser({ pathname: '/login', href: 'https://oms.test/login' });
        recoverExpiredSession();

        assert.deepEqual(browser.assigns, ['/login']);
        assert.equal(browser.reloads, 0);

        browser.assigns = [];
        installBrowser({ pathname: '/login/reset' });
        recoverExpiredSession();

        assert.deepEqual(browser.assigns, ['/login']);
        assert.equal(browser.reloads, 0);
        assert.deepEqual(visits, []);
    });

    it('retries the current document as a fresh get visit', () => {
        installBrowser({ href: 'https://oms.test/organization/crew?view=1' });

        retryCurrentDocument();

        assert.deepEqual(visits, [
            {
                href: 'https://oms.test/organization/crew?view=1',
                options: { preserveScroll: true, preserveState: false },
            },
        ]);
    });
});
