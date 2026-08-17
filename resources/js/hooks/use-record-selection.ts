import { useCallback, useMemo, useState } from 'react';
import {
    addSelectedIds,
    headerCheckboxState,
    isAllVisibleSelected,
    isSomeVisibleSelected,
    removeSelectedIds,
    toggleSelectedId,
    toggleVisibleIds,
    visibleSelectedIds,
} from '@/lib/record-selection';
import type { CheckboxState } from '@/lib/record-selection';

export type RecordSelection<T extends string | number> = {
    /**
     * Visible-page intersection. Bulk Documents and other existing callers
     * rely on this remaining a visible-only list.
     */
    selectedIds: T[];
    selectedCount: number;
    visibleSelectedIds: T[];
    /**
     * Persistent manually selected IDs. Survives pagination when the caller
     * does not remount the hook. Use this for export-selected scope.
     */
    allSelectedIds: T[];
    allSelectedCount: number;
    isSelected: (id: T) => boolean;
    isAllSelected: boolean;
    isPartiallySelected: boolean;
    allVisibleSelected: boolean;
    someVisibleSelected: boolean;
    headerCheckboxState: CheckboxState;
    toggle: (id: T) => void;
    toggleAll: () => void;
    toggleVisible: () => void;
    toggleAllVisible: () => void;
    select: (ids: T[]) => void;
    deselect: (ids: T[]) => void;
    clear: () => void;
};

export function useRecordSelection<T extends string | number>(
    visibleIds: T[],
): RecordSelection<T> {
    const [selected, setSelected] = useState<Set<T>>(() => new Set());

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
        setSelected((current) => toggleSelectedId(current, id));
    }, []);

    const toggleAll = useCallback(() => {
        setSelected((current) => toggleVisibleIds(current, visibleIds));
    }, [visibleIds]);

    const select = useCallback((ids: T[]) => {
        setSelected((current) => addSelectedIds(current, ids));
    }, []);

    const deselect = useCallback((ids: T[]) => {
        setSelected((current) => removeSelectedIds(current, ids));
    }, []);

    const clear = useCallback(() => {
        setSelected(new Set());
    }, []);

    const isSelected = useCallback(
        (id: T) => visibleSelection.has(id),
        [visibleSelection],
    );

    const selectedIds = useMemo(
        () => visibleSelectedIds(selected, visibleIds),
        [selected, visibleIds],
    );
    const allSelectedIds = useMemo(() => Array.from(selected), [selected]);

    const allVisibleSelected = isAllVisibleSelected(
        visibleSelection,
        visibleIds,
    );
    const someVisibleSelected =
        !allVisibleSelected &&
        isSomeVisibleSelected(visibleSelection, visibleIds);

    return {
        selectedIds,
        selectedCount: visibleSelection.size,
        visibleSelectedIds: selectedIds,
        allSelectedIds,
        allSelectedCount: selected.size,
        isSelected,
        isAllSelected: allVisibleSelected,
        isPartiallySelected: someVisibleSelected,
        allVisibleSelected,
        someVisibleSelected,
        headerCheckboxState: headerCheckboxState(
            allVisibleSelected,
            someVisibleSelected,
        ),
        toggle,
        toggleAll,
        toggleVisible: toggleAll,
        toggleAllVisible: toggleAll,
        select,
        deselect,
        clear,
    };
}
