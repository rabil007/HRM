export function formatPermissionGroupLabel(segment: string): string {
    return segment.replace(/[-_]/g, ' ').toUpperCase();
}

const PERMISSION_MAIN_GROUP_ALIASES: Record<string, string> = {
    company_documents: 'companies',
    bulk_documents: 'documents',
};

export function resolvePermissionGroups(permission: string): {
    mainGroup: string;
    subGroup: string;
} {
    const parts = permission.split('.');
    const root = parts[0] || 'other';
    const mainGroup = formatPermissionGroupLabel(
        PERMISSION_MAIN_GROUP_ALIASES[root] ?? root,
    );

    let subGroup = 'GENERAL';

    if (root === 'company_documents') {
        subGroup = 'DOCUMENTS';
    } else if (root === 'bulk_documents') {
        subGroup = 'GENERATE & TRACK';
    } else if (parts.length > 2) {
        subGroup = parts
            .slice(1, -1)
            .map((p) => formatPermissionGroupLabel(p))
            .join(' • ');
    } else if (parts.length === 2 && mainGroup === 'SETTINGS') {
        subGroup = 'CORE';
    }

    return { mainGroup, subGroup };
}
