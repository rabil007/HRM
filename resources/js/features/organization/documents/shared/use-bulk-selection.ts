import { useCallback, useMemo, useState } from 'react';

export function useBulkSelection<T extends string | number>(visibleIds: T[]) {
    const [selected, setSelected] = useState<Set<T>>(new Set());

    const visibleSet = useMemo(() => new Set(visibleIds), [visibleIds]);

    const visibleSelection = useMemo(() => {
        const next = new Set<T>();

        selected.forEach((id) => {
            if (visibleSet.has(id)) {
                next.add(id);
            }
        });

        return next;
    }, [selected, visibleSet]);

    const toggle = useCallback((id: T) => {
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }, []);

    const toggleAll = useCallback(() => {
        setSelected((current) => {
            const allVisibleSelected =
                visibleIds.length > 0 &&
                visibleIds.every((id) => current.has(id));

            return allVisibleSelected ? new Set<T>() : new Set(visibleIds);
        });
    }, [visibleIds]);

    const clear = useCallback(() => {
        setSelected(new Set());
    }, []);

    const isSelected = useCallback(
        (id: T) => visibleSelection.has(id),
        [visibleSelection],
    );

    const selectedIds = useMemo(
        () => Array.from(visibleSelection),
        [visibleSelection],
    );

    const isAllSelected =
        visibleIds.length > 0 &&
        visibleIds.every((id) => visibleSelection.has(id));

    const isPartiallySelected =
        !isAllSelected && visibleIds.some((id) => visibleSelection.has(id));

    return {
        selectedIds,
        selectedCount: visibleSelection.size,
        isSelected,
        isAllSelected,
        isPartiallySelected,
        toggle,
        toggleAll,
        clear,
    };
}
