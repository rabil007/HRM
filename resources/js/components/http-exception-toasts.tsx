import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import {
    isInertiaErrorPageResponse,
    readHttpExceptionResponse,
} from '@/lib/errors/inertia-http-exception';
import {
    notifyNetworkFailure,
    notifyOffline,
    notifyOnline,
    resetNetworkRecoveryState,
} from '@/lib/errors/network-recovery';
import { toast } from '@/lib/toast';

/** Single place for server flash toasts after Inertia visits — do not duplicate with manual toast.success in onSuccess. */
const lastShownFlash: Partial<Record<'success' | 'error' | 'info', string>> =
    {};

const STATUSES_WITH_FULL_PAGE = new Set([401, 403, 404, 419, 429, 500, 503]);

function showFlashToast(
    type: 'success' | 'error' | 'info',
    message: string,
): void {
    if (!message) {
        delete lastShownFlash[type];

        return;
    }

    if (lastShownFlash[type] === message) {
        return;
    }

    lastShownFlash[type] = message;
    toast[type](message);
}

export function HttpExceptionToasts() {
    useEffect(() => {
        const removeSuccess = router.on('success', (event) => {
            resetNetworkRecoveryState();

            const pageProps = event.detail.page.props as {
                flash?: Partial<Record<'success' | 'error' | 'info', string>>;
            };
            const flash = pageProps.flash ?? {};

            const success =
                typeof flash.success === 'string' ? flash.success.trim() : '';
            const error =
                typeof flash.error === 'string' ? flash.error.trim() : '';
            const info =
                typeof flash.info === 'string' ? flash.info.trim() : '';

            showFlashToast('success', success);
            showFlashToast('error', error);
            showFlashToast('info', info);
        });

        const removeHttpException = router.on('httpException', (event) => {
            const response = readHttpExceptionResponse(event);
            const status = response?.status;

            if (status === 403) {
                if (isInertiaErrorPageResponse(response)) {
                    return;
                }

                toast.error("You don't have permission to do this action.");

                return;
            }

            if (status === 413) {
                toast.error(
                    'File is too large for the server upload limit. Try a smaller file or ask an admin to raise PHP upload limits.',
                );

                return;
            }

            if (status === 419) {
                if (isInertiaErrorPageResponse(response)) {
                    return;
                }

                toast.error(
                    'Your session expired. Refresh the page and sign in again if needed.',
                );

                return;
            }

            if (
                typeof status === 'number' &&
                STATUSES_WITH_FULL_PAGE.has(status) &&
                isInertiaErrorPageResponse(response)
            ) {
                return;
            }
        });

        const removeNetworkError = router.on('networkError', () => {
            notifyNetworkFailure();
        });

        const handleOffline = () => notifyOffline();
        const handleOnline = () => notifyOnline();

        window.addEventListener('offline', handleOffline);
        window.addEventListener('online', handleOnline);

        return () => {
            removeSuccess();
            removeHttpException();
            removeNetworkError();
            window.removeEventListener('offline', handleOffline);
            window.removeEventListener('online', handleOnline);
        };
    }, []);

    return null;
}
