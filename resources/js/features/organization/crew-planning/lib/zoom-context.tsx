import type { ReactElement, ReactNode } from 'react';
import { createContext, useCallback, useContext, useState } from 'react';

export type ZoomLevel = 'compact' | 'normal' | 'wide';

export const DAY_WIDTH: Record<ZoomLevel, number> = {
    compact: 24,
    normal: 32,
    wide: 48,
};

type ZoomContextValue = {
    zoom: ZoomLevel;
    dayWidth: number;
    setZoom: (zoom: ZoomLevel) => void;
    zoomIn: () => void;
    zoomOut: () => void;
};

const ZOOM_LEVELS: ZoomLevel[] = ['compact', 'normal', 'wide'];

const ZoomContext = createContext<ZoomContextValue>({
    zoom: 'normal',
    dayWidth: DAY_WIDTH.normal,
    setZoom: () => {},
    zoomIn: () => {},
    zoomOut: () => {},
});

export function ZoomProvider({ children }: { children: ReactNode }): ReactElement {
    const [zoom, setZoomState] = useState<ZoomLevel>('normal');

    const setZoom = useCallback((next: ZoomLevel): void => {
        setZoomState(next);
    }, []);

    const zoomIn = useCallback((): void => {
        setZoomState((prev) => {
            const idx = ZOOM_LEVELS.indexOf(prev);

            return ZOOM_LEVELS[Math.min(idx + 1, ZOOM_LEVELS.length - 1)];
        });
    }, []);

    const zoomOut = useCallback((): void => {
        setZoomState((prev) => {
            const idx = ZOOM_LEVELS.indexOf(prev);

            return ZOOM_LEVELS[Math.max(idx - 1, 0)];
        });
    }, []);

    return (
        <ZoomContext.Provider value={{ zoom, dayWidth: DAY_WIDTH[zoom], setZoom, zoomIn, zoomOut }}>
            {children}
        </ZoomContext.Provider>
    );
}

export function useZoom(): ZoomContextValue {
    return useContext(ZoomContext);
}
