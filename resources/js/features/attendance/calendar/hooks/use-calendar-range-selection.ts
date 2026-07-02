import { useCallback, useEffect, useState } from 'react';

export type CalendarDateRange = {
    start: string;
    end: string;
};

export function normalizeCalendarDateRange(
    start: string,
    end: string,
): CalendarDateRange {
    return start <= end ? { start, end } : { start: end, end: start };
}

export function isDateInRange(
    date: string,
    start: string | null,
    end: string | null,
): boolean {
    if (start === null || end === null) {
        return false;
    }

    const range = normalizeCalendarDateRange(start, end);

    return date >= range.start && date <= range.end;
}

export function useCalendarRangeSelection({
    enabled,
    onRangeComplete,
}: {
    enabled: boolean;
    onRangeComplete: (range: CalendarDateRange) => void;
}) {
    const [anchorDate, setAnchorDate] = useState<string | null>(null);
    const [focusDate, setFocusDate] = useState<string | null>(null);
    const [isSelecting, setIsSelecting] = useState(false);

    const resetSelection = useCallback(() => {
        setAnchorDate(null);
        setFocusDate(null);
        setIsSelecting(false);
    }, []);

    const beginSelection = useCallback(
        (date: string) => {
            if (!enabled) {
                return;
            }

            setAnchorDate(date);
            setFocusDate(date);
            setIsSelecting(true);
        },
        [enabled],
    );

    const extendSelection = useCallback(
        (date: string) => {
            if (!enabled || !isSelecting) {
                return;
            }

            setFocusDate(date);
        },
        [enabled, isSelecting],
    );

    const completeSelection = useCallback(() => {
        if (
            !enabled ||
            !isSelecting ||
            anchorDate === null ||
            focusDate === null
        ) {
            resetSelection();

            return;
        }

        const range = normalizeCalendarDateRange(anchorDate, focusDate);
        resetSelection();
        onRangeComplete(range);
    }, [
        anchorDate,
        enabled,
        focusDate,
        isSelecting,
        onRangeComplete,
        resetSelection,
    ]);

    useEffect(() => {
        if (!isSelecting) {
            return;
        }

        const handlePointerUp = () => {
            completeSelection();
        };

        document.addEventListener('pointerup', handlePointerUp);

        return () => {
            document.removeEventListener('pointerup', handlePointerUp);
        };
    }, [completeSelection, isSelecting]);

    return {
        anchorDate,
        focusDate,
        isSelecting,
        beginSelection,
        extendSelection,
        isDateInRange: (date: string) =>
            isDateInRange(date, anchorDate, focusDate),
    };
}
