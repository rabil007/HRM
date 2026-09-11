import type { UserFormData } from '../types';

export function buildUserFormPayload(
    data: UserFormData,
    spoofPut: boolean,
): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        name: data.name,
        email: data.email,
        use_employee_avatar: data.use_employee_avatar,
        employee_id: data.employee_id,
        role_id: data.role_id,
        status: data.status,
    };

    if (data.avatar instanceof File) {
        payload.avatar = data.avatar;
    }

    if (spoofPut) {
        payload._method = 'put';
    }

    return payload;
}
