import { useCallback, useEffect, useMemo, useState } from 'react';

export function useBulkSelection<T extends string | number>(visibleIds: T[]) {
    const [selected, setSelected] = useState<Set<T>>(new Set());

    const visibleKey = visibleIds.join('|');

    useEffect(() => {
        setSelected((current) => {
            const visibleSet = new Set(visibleIds);
            const next = new Set<T>();

            current.forEach((id) => {
                if (visibleSet.has(id)) {
                    next.add(id);
                }
            });

            return next;
        });
    }, [visibleKey, visibleIds]);

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
            const allSelected =
                visibleIds.length > 0 && visibleIds.every((id) => current.has(id));

            return allSelected ? new Set<T>() : new Set(visibleIds);
        });
    }, [visibleIds]);

    const clear = useCallback(() => {
        setSelected(new Set());
    }, []);

    const isSelected = useCallback((id: T) => selected.has(id), [selected]);

    const selectedIds = useMemo(() => Array.from(selected), [selected]);

    const isAllSelected =
        visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    const isPartiallySelected =
        !isAllSelected && visibleIds.some((id) => selected.has(id));

    return {
        selectedIds,
        selectedCount: selected.size,
        isSelected,
        isAllSelected,
        isPartiallySelected,
        toggle,
        toggleAll,
        clear,
    };
}
