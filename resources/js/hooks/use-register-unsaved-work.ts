import { useEffect } from 'react';
import { registerUnsavedWorkChecker } from '@/lib/app-refresh/has-unsaved-work';

/**
 * Register a dirty flag with the app-refresh reload guard.
 * Prefer this when a form does not already use a beforeunload listener.
 */
export function useRegisterUnsavedWork(isDirty: boolean): void {
    useEffect(() => {
        if (!isDirty) {
            return;
        }

        return registerUnsavedWorkChecker(() => true);
    }, [isDirty]);
}
