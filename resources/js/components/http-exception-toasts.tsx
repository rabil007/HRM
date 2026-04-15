import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from '@/lib/toast';

export function HttpExceptionToasts() {
    useEffect(() => {
        const removeHttpException = router.on('httpException', (response: any) => {
            const status = response?.status;

            if (status === 403) {
                toast.error("You don't have permission to do this action.");
            }
        });

        const removeNetworkError = router.on('networkError', () => {
            toast.error('Network error. Please try again.');
        });

        return () => {
            removeHttpException();
            removeNetworkError();
        };
    }, []);

    return null;
}

