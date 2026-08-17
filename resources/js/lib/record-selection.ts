export type CheckboxState = boolean | 'indeterminate';

export function visibleSelectedIds<T extends string | number>(
    selected: Set<T>,
    visibleIds: T[],
): T[] {
    const visible = new Set(visibleIds);
    const next: T[] = [];

    selected.forEach((id) => {
        if (visible.has(id)) {
            next.push(id);
        }
    });

    return next;
}

export function isAllVisibleSelected<T extends string | number>(
    selected: Set<T>,
    visibleIds: T[],
): boolean {
    return visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));
}

export function isSomeVisibleSelected<T extends string | number>(
    selected: Set<T>,
    visibleIds: T[],
): boolean {
    return visibleIds.some((id) => selected.has(id));
}

export function headerCheckboxState(
    allVisibleSelected: boolean,
    someVisibleSelected: boolean,
): CheckboxState {
    if (allVisibleSelected) {
        return true;
    }

    if (someVisibleSelected) {
        return 'indeterminate';
    }

    return false;
}

export function groupCheckboxState<T extends string | number>(
    selected: Set<T>,
    groupIds: T[],
): CheckboxState {
    if (groupIds.length === 0) {
        return false;
    }

    const selectedCount = groupIds.filter((id) => selected.has(id)).length;

    if (selectedCount === 0) {
        return false;
    }

    if (selectedCount === groupIds.length) {
        return true;
    }

    return 'indeterminate';
}

export function toggleSelectedId<T extends string | number>(
    selected: Set<T>,
    id: T,
): Set<T> {
    const next = new Set(selected);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    return next;
}

export function toggleVisibleIds<T extends string | number>(
    selected: Set<T>,
    visibleIds: T[],
): Set<T> {
    return isAllVisibleSelected(selected, visibleIds)
        ? new Set<T>()
        : new Set(visibleIds);
}

export function addSelectedIds<T extends string | number>(
    selected: Set<T>,
    ids: T[],
): Set<T> {
    const next = new Set(selected);

    ids.forEach((id) => {
        next.add(id);
    });

    return next;
}

export function removeSelectedIds<T extends string | number>(
    selected: Set<T>,
    ids: T[],
): Set<T> {
    const next = new Set(selected);

    ids.forEach((id) => {
        next.delete(id);
    });

    return next;
}
