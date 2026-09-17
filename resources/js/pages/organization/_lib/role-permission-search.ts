import type { PermissionOption } from '@/features/organization/roles/types';

export function permissionMatchesQuery(
    permission: PermissionOption,
    query: string,
): boolean {
    const normalized = query.trim().toLowerCase();

    if (normalized === '') {
        return true;
    }

    return [
        permission.label,
        permission.name,
        permission.description ?? '',
        permission.group,
    ].some((value) => value.toLowerCase().includes(normalized));
}
