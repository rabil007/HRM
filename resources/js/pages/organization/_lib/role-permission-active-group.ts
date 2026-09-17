export function resolveEffectiveActiveGroup(
    grouped: readonly (readonly [string, unknown])[],
    activeGroup: string | null,
): string | null {
    if (grouped.length === 0) {
        return null;
    }

    if (
        activeGroup !== null &&
        grouped.some(([groupName]) => groupName === activeGroup)
    ) {
        return activeGroup;
    }

    return grouped[0][0];
}
