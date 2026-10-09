/**
 * Pure helpers for Inertia visit settlement used by app-refresh.
 * Success must never be inferred from onFinish alone.
 */

export type InertiaVisitEvent =
    | 'success'
    | 'error'
    | 'cancel'
    | 'finish'
    | 'http_401'
    | 'http_403'
    | 'http_419';

export type InertiaVisitAction =
    | 'succeed'
    | 'fail'
    | 'login'
    | 'forbidden'
    | 'ignore';

export function resolveInertiaVisitAction(
    event: InertiaVisitEvent,
    settled: boolean,
): InertiaVisitAction {
    if (settled) {
        return 'ignore';
    }

    switch (event) {
        case 'success':
            return 'succeed';
        case 'http_401':
        case 'http_419':
            return 'login';
        case 'http_403':
            return 'forbidden';
        case 'error':
        case 'cancel':
        case 'finish':
            return 'fail';
        default:
            return 'fail';
    }
}

/** Authorization revision advances only after a successful Inertia response. */
export function shouldAdvanceAuthorizationRevision(
    action: InertiaVisitAction,
): boolean {
    return action === 'succeed';
}

/** Combined token shape: `{userRevision}.{companyRevision}`. */
const AUTHORIZATION_REVISION_PATTERN = /^(\d+)\.(\d+)$/;

export type AuthorizationRevisionParts = {
    user: number;
    company: number;
};

/**
 * Parse a server authorization revision token.
 * Empty / non-string / malformed values are rejected.
 */
export function parseAuthorizationRevision(
    value: unknown,
): AuthorizationRevisionParts | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const match = AUTHORIZATION_REVISION_PATTERN.exec(value);

    if (!match) {
        return null;
    }

    return {
        user: Number(match[1]),
        company: Number(match[2]),
    };
}

export function isValidAuthorizationRevision(value: unknown): value is string {
    return parseAuthorizationRevision(value) !== null;
}

/**
 * Lexicographic compare on (user, company). Positive when `left` is newer.
 */
export function compareAuthorizationRevisions(
    left: AuthorizationRevisionParts,
    right: AuthorizationRevisionParts,
): number {
    if (left.user !== right.user) {
        return left.user - right.user;
    }

    return left.company - right.company;
}

/**
 * Decide which local authorization revision to store after a successful
 * Inertia authorization reload. Never invents a revision from `expectedRevision`
 * alone — HTTP success without a valid response revision is not a sync success.
 *
 * Returns the revision string to commit, or `null` to keep the previous local
 * revision so the next poll retries.
 *
 * Company switches may lower the company counter while the user counter stays
 * the same. Ordering is therefore against the polled `expectedRevision` only:
 * commit a validated response token that matches or is newer than expected.
 */
export function resolveSyncedAuthorizationRevision(options: {
    expectedRevision: string;
    receivedRevision: unknown;
}): string | null {
    const received = parseAuthorizationRevision(options.receivedRevision);

    if (received === null) {
        return null;
    }

    const receivedToken = `${received.user}.${received.company}`;
    const expected = parseAuthorizationRevision(options.expectedRevision);

    // Poll reported a malformed expected token — still accept a valid response
    // revision rather than inventing success from the raw expected string.
    if (expected === null) {
        return receivedToken;
    }

    // Stale relative to the poll: response still behind what /app/version reported.
    if (compareAuthorizationRevisions(received, expected) < 0) {
        return null;
    }

    // Matching or newer-than-expected (another bump during the reload).
    return receivedToken;
}

export function shouldReportManualRefreshSuccess(
    action: InertiaVisitAction,
): boolean {
    return action === 'succeed';
}
