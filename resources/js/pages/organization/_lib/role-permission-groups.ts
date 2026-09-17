export function formatPermissionGroupLabel(segment: string): string {
    return segment
        .replace(/[-_]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

export function resolvePermissionSubGroup(permissionName: string): string {
    const parts = permissionName.split('.');
    const root = parts[0] || 'other';

    if (root === 'company_documents') {
        return 'Documents';
    }

    if (root === 'bulk_documents') {
        return 'Generate & Track';
    }

    if (parts.length > 2) {
        return parts
            .slice(1, -1)
            .map((part) => formatPermissionGroupLabel(part))
            .join(' • ');
    }

    if (parts.length === 2 && root === 'settings') {
        return 'Core';
    }

    return 'General';
}

export function resolvePermissionGroups(
    permissionName: string,
    registryGroup?: string | null,
): {
    mainGroup: string;
    subGroup: string;
} {
    const mainGroup =
        registryGroup?.trim() ||
        formatPermissionGroupLabel(permissionName.split('.')[0] || 'other');

    return {
        mainGroup,
        subGroup: resolvePermissionSubGroup(permissionName),
    };
}
