import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

export function useAuthPermissions(): string[] {
    return (
        (usePage().props.auth as { permissions?: string[] } | undefined)?.permissions ?? []
    );
}

export function useHasPermission(permission: string): boolean {
    const permissions = useAuthPermissions();

    return useMemo(
        () => permissions.includes(permission),
        [permission, permissions],
    );
}

export function useAttendanceTypesCan(): {
    create: boolean;
    update: boolean;
    delete: boolean;
} {
    const permissions = useAuthPermissions();

    return useMemo(
        () => ({
            create: permissions.includes('attendance.types.create'),
            update: permissions.includes('attendance.types.update'),
            delete: permissions.includes('attendance.types.delete'),
        }),
        [permissions],
    );
}

export function useSettingsMasterDataCan(resource: string): {
    create: boolean;
    update: boolean;
    delete: boolean;
} {
    const permissions = useAuthPermissions();
    const prefix = `settings.master-data.${resource}`;

    return useMemo(
        () => ({
            create: permissions.includes(`${prefix}.create`),
            update: permissions.includes(`${prefix}.update`),
            delete: permissions.includes(`${prefix}.delete`),
        }),
        [permissions, prefix],
    );
}
