import { useCallback, useEffect, useRef, useState } from 'react';
import type { MouseEvent, PointerEvent, ReactElement, ReactNode } from 'react';
import { createPortal } from 'react-dom';

export const NEEDS_UPDATE_TOOLTIP_DELAY_MS = 500;

export function DelayedHoverHint({
    hint,
    delayMs = NEEDS_UPDATE_TOOLTIP_DELAY_MS,
    children,
    stopRowNavigation = false,
}: {
    hint: string;
    delayMs?: number;
    children: ReactNode;
    stopRowNavigation?: boolean;
}): ReactElement {
    const anchorRef = useRef<HTMLSpanElement>(null);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState({ top: 0, left: 0 });

    const clearTimer = useCallback(() => {
        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    const updatePosition = useCallback(() => {
        const rect = anchorRef.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        setPosition({
            top: rect.top - 8,
            left: rect.left + rect.width / 2,
        });
    }, []);

    const handleEnter = useCallback(() => {
        clearTimer();
        timerRef.current = setTimeout(() => {
            updatePosition();
            setOpen(true);
        }, delayMs);
    }, [clearTimer, delayMs, updatePosition]);

    const handleLeave = useCallback(() => {
        clearTimer();
        setOpen(false);
    }, [clearTimer]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const reposition = (): void => {
            updatePosition();
        };

        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return () => {
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    }, [open, updatePosition]);

    useEffect(() => () => clearTimer(), [clearTimer]);

    const stopPropagation = (
        event: MouseEvent<HTMLSpanElement> | PointerEvent<HTMLSpanElement>,
    ): void => {
        if (stopRowNavigation) {
            event.stopPropagation();
        }
    };

    return (
        <>
            <span
                ref={anchorRef}
                className="inline-flex cursor-help"
                onMouseEnter={handleEnter}
                onMouseLeave={handleLeave}
                onFocus={handleEnter}
                onBlur={handleLeave}
                onClick={stopPropagation}
                onPointerDown={stopPropagation}
            >
                {children}
            </span>
            {open &&
                createPortal(
                    <div
                        role="tooltip"
                        className="pointer-events-none fixed z-[200] max-w-xs -translate-x-1/2 -translate-y-full rounded-md border border-border bg-popover px-3 py-2 text-center text-xs leading-snug text-popover-foreground shadow-lg"
                        style={{ top: position.top, left: position.left }}
                    >
                        {hint}
                    </div>,
                    document.body,
                )}
        </>
    );
}
