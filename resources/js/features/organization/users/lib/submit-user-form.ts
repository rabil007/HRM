import type { InertiaFormProps } from '@inertiajs/react';
import { firstValidationError } from '@/lib/first-validation-error';
import { toast } from '@/lib/toast';
import {
    store as storeUser,
    update as updateUser,
} from '@/routes/organization/users';
import type { UserFormData } from '../types';
import { buildUserFormPayload } from './user-form-payload';

export function submitUserForm(
    form: InertiaFormProps<UserFormData>,
    userId: number | null,
    onSuccess: () => void,
): void {
    const spoofPut = userId !== null;

    form.transform((data) => buildUserFormPayload(data, spoofPut));

    form.post(spoofPut ? updateUser.url(userId) : storeUser.url(), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess,
        onError: (errors) => {
            toast.error(
                firstValidationError(
                    errors,
                    'name',
                    'Failed to save user. Please try again.',
                ),
            );
        },
    });
}
