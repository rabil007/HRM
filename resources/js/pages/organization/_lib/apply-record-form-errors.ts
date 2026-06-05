import type { FormDataConvertible } from '@inertiajs/core';
import { firstValidationError } from '@/lib/first-validation-error';
import { toast } from '@/lib/toast';

type FormWithErrors<T extends Record<string, FormDataConvertible>> = {
    setError: (field: keyof T & string, message: string) => void;
};

/**
 * Surface Inertia validation errors on record dialogs (field errors + toast).
 */
export function applyRecordFormErrors<T extends Record<string, FormDataConvertible>>(
    form: FormWithErrors<T>,
    errors: Record<string, string | string[]>,
    fallbackMessage = 'Could not save. Please check the highlighted fields.',
): void {
    Object.entries(errors).forEach(([key, message]) => {
        if (key === '_') {
            return;
        }

        const resolved = Array.isArray(message) ? (message[0] ?? '') : message;

        if (resolved.trim() !== '') {
            form.setError(key as keyof T & string, resolved);
        }
    });

    toast.error(firstValidationError(errors, '_', fallbackMessage));
}
