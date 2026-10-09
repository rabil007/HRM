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

/**
 * After a forbidden page reload, adopt the revision only once navigation to an
 * authorized destination succeeds.
 */
export function shouldReportManualRefreshSuccess(
    action: InertiaVisitAction,
): boolean {
    return action === 'succeed';
}
