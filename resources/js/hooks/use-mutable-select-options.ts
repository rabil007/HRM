import { useCallback, useEffect, useMemo, useState } from 'react';
import type { CreatableOption } from '@/components/ui/creatable-select';

type SourceOption = {
    id: number | string;
    name?: string | null;
    title?: string | null;
};

export function useMutableSelectOptions<T extends SourceOption>(
    initial: T[],
    labelKey: 'name' | 'title' = 'name',
): {
    sourceItems: T[];
    selectOptions: CreatableOption[];
    appendOption: (entry: { id: number | string; label: string }) => void;
} {
    const [sourceItems, setSourceItems] = useState(initial);

    useEffect(() => {
        setSourceItems(initial);
    }, [initial]);

    const selectOptions = useMemo(
        () =>
            sourceItems.map((item) => ({
                id: item.id,
                label:
                    (labelKey === 'title' ? item.title : item.name) ??
                    `#${item.id}`,
                value: String(item.id),
            })),
        [labelKey, sourceItems],
    );

    const appendOption = useCallback(
        (entry: { id: number | string; label: string }) => {
            setSourceItems((previous) => {
                if (
                    previous.some(
                        (item) => String(item.id) === String(entry.id),
                    )
                ) {
                    return previous;
                }

                const nextItem = {
                    id: entry.id,
                    [labelKey]: entry.label,
                } as T;

                return [...previous, nextItem];
            });
        },
        [labelKey],
    );

    return {
        sourceItems,
        selectOptions,
        appendOption,
    };
}
