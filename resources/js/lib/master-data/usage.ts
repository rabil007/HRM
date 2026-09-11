export type MasterDataUsageFlags = {
    is_in_use?: boolean;
    can_delete?: boolean;
    usage_count?: number | null;
    usage_label?: string | null;
};

export const MASTER_DATA_DELETE_BLOCKED_MESSAGE =
    'This record is currently in use. Delete is unavailable.';

export function masterDataIsInUse(item: MasterDataUsageFlags): boolean {
    return item.is_in_use === true;
}

export function masterDataCanDelete(
    item: MasterDataUsageFlags,
    hasDeletePermission: boolean,
): boolean {
    if (!hasDeletePermission) {
        return false;
    }

    if (typeof item.can_delete === 'boolean') {
        return item.can_delete;
    }

    return !masterDataIsInUse(item);
}

export function masterDataUsageTooltip(
    item: MasterDataUsageFlags,
): string | null {
    if (!masterDataIsInUse(item)) {
        return null;
    }

    if (item.usage_label && item.usage_label.trim() !== '') {
        return `Used by ${item.usage_label}. Delete is unavailable.`;
    }

    if (typeof item.usage_count === 'number' && item.usage_count > 0) {
        const noun = item.usage_count === 1 ? 'record' : 'records';

        return `Used by ${item.usage_count} ${noun}. Delete is unavailable.`;
    }

    return MASTER_DATA_DELETE_BLOCKED_MESSAGE;
}
