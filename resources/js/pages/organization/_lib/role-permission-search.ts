import type { PermissionOption } from '@/features/organization/roles/types';
import { resolvePermissionGroups } from './role-permission-groups.ts';

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
        resolvePermissionGroups(permission.name, permission.group).subGroup,
    ].some((value) => value.toLowerCase().includes(normalized));
}
