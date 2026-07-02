import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/lib/toast';

/** Single place for server flash toasts after Inertia visits — do not duplicate with manual toast.success in onSuccess. */
let lastFlashToast: { key: string; at: number } | null = null;

function showFlashToast(
    type: 'success' | 'error' | 'info',
    message: string,
): void {
    const key = `${type}:${message}`;
    const now = Date.now();

    if (lastFlashToast?.key === key && now - lastFlashToast.at < 750) {
        return;
    }

    lastFlashToast = { key, at: now };
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

            if (success) {
                showFlashToast('success', success);
            }

            if (error) {
                showFlashToast('error', error);
            }

            if (info) {
                showFlashToast('info', info);
            }
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
