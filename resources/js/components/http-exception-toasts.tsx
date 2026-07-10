import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/lib/toast';

/** Single place for server flash toasts after Inertia visits — do not duplicate with manual toast.success in onSuccess. */
const lastShownFlash: Partial<
    Record<'success' | 'error' | 'info', string>
> = {};

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
        const removeSuccess = router.on('success', (event: any) => {
            const flash = event?.detail?.page?.props?.flash ?? {};

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

        const removeHttpException = router.on(
            'httpException',
            (response: any) => {
                const status = response?.status;

                if (status === 403) {
                    toast.error("You don't have permission to do this action.");
                }
            },
        );

        const removeNetworkError = router.on('networkError', () => {
            toast.error('Network error. Please try again.');
        });

        return () => {
            removeSuccess();
            removeHttpException();
            removeNetworkError();
        };
    }, []);

    return null;
}
