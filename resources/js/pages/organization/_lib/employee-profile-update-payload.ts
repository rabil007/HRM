import type { TemplateFieldConfig } from '../employee-page.types.ts';
import {
    transformEmployeeProfileFormData,
    type EmployeeProfileFormData,
} from './employee-profile-form-state.ts';

export type EmployeeProfileSaveVisit = {
    httpMethod: 'post' | 'put';
    forceFormData: boolean;
};

/**
 * PHP and nginx on production do not populate uploaded files for real HTTP PUT
 * requests with multipart/form-data. Inertia must POST with `_method=put` instead.
 *
 * Golden references:
 * - `resources/js/features/organization/users/lib/submit-user-form.ts`
 * - `resources/js/pages/organization/_hooks/use-employee-profile-form.tsx`
 * - `tests/Feature/Organization/EmployeesTest.php` (photo upload spoof test)
 */
export function employeeProfileUpdateRequiresPostSpoof(
    image: EmployeeProfileFormData['image'] | unknown,
): boolean {
    return image instanceof File;
}

export function resolveEmployeeProfileSaveVisit(
    image: EmployeeProfileFormData['image'] | unknown,
): EmployeeProfileSaveVisit {
    const requiresPostSpoof = employeeProfileUpdateRequiresPostSpoof(image);

    return {
        httpMethod: requiresPostSpoof ? 'post' : 'put',
        forceFormData: requiresPostSpoof,
    };
}

export function buildEmployeeProfileUpdatePayload(
    data: Record<string, unknown>,
    templateEmployeeFields?: Record<string, TemplateFieldConfig>,
): Record<string, unknown> {
    const payload = transformEmployeeProfileFormData(
        data,
        templateEmployeeFields,
    );

    if (data.image instanceof File) {
        payload.image = data.image;
        payload._method = 'put';
    }

    if (data.remove_image) {
        payload.remove_image = true;
    }

    return payload;
}
