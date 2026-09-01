type NormalizedRect = {
    x: number;
    y: number;
    width: number;
    height: number;
};

type PixelRect = {
    left: number;
    top: number;
    width: number;
    height: number;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

export type DesignHistoryState<TPlacements, TSignatures> = {
    placements: TPlacements;
    signaturePlacements: TSignatures;
};

export function cloneDesignState<TPlacements, TSignatures>(
    state: DesignHistoryState<TPlacements, TSignatures>,
): DesignHistoryState<TPlacements, TSignatures> {
    return {
        placements: structuredClone(state.placements),
        signaturePlacements: structuredClone(state.signaturePlacements),
    };
}

export class DesignHistory<TPlacements, TSignatures> {
    private past: DesignHistoryState<TPlacements, TSignatures>[] = [];

    private future: DesignHistoryState<TPlacements, TSignatures>[] = [];

    private readonly limit: number;

    constructor(limit = 50) {
        this.limit = limit;
    }

    push(current: DesignHistoryState<TPlacements, TSignatures>): void {
        this.accept(cloneDesignState(current));
    }

    accept(snapshot: DesignHistoryState<TPlacements, TSignatures>): void {
        this.past.push(snapshot);

        if (this.past.length > this.limit) {
            this.past.shift();
        }

        this.future = [];
    }

    undo(
        current: DesignHistoryState<TPlacements, TSignatures>,
    ): DesignHistoryState<TPlacements, TSignatures> | null {
        const previous = this.past.pop();

        if (!previous) {
            return null;
        }

        this.future.push(cloneDesignState(current));

        return previous;
    }

    redo(
        current: DesignHistoryState<TPlacements, TSignatures>,
    ): DesignHistoryState<TPlacements, TSignatures> | null {
        const next = this.future.pop();

        if (!next) {
            return null;
        }

        this.past.push(cloneDesignState(current));

        return next;
    }

    get canUndo(): boolean {
        return this.past.length > 0;
    }

    get canRedo(): boolean {
        return this.future.length > 0;
    }
}

export type ClickVerticalAlign = 'top' | 'middle' | 'baseline';

export type OverlayTextAlign = 'left' | 'center' | 'right';

/**
 * Top of a nowrap overlay value inside its box. Matches generated CSS
 * flex-start / center / flex-end (baseline sits on the box floor).
 */
export function overlayTextTopForAlign(
    rectTop: number,
    rectHeight: number,
    textHeight: number,
    verticalAlign: ClickVerticalAlign,
): number {
    const height = Math.min(Math.max(1, textHeight), rectHeight);

    if (verticalAlign === 'top') {
        return rectTop;
    }

    if (verticalAlign === 'baseline') {
        return rectTop + rectHeight - height;
    }

    return rectTop + (rectHeight - height) / 2;
}

export function overlayFieldLabelLayout(
    left: number,
    top: number,
    width: number,
    height: number,
    align: OverlayTextAlign,
    verticalAlign: ClickVerticalAlign,
    textHeight: number,
): {
    left: number;
    top: number;
    originX: OverlayTextAlign;
    originY: 'top';
} {
    let labelLeft = left;
    let originX: OverlayTextAlign = 'left';

    if (align === 'center') {
        labelLeft = left + width / 2;
        originX = 'center';
    } else if (align === 'right') {
        labelLeft = left + width;
        originX = 'right';
    }

    return {
        left: labelLeft,
        top: overlayTextTopForAlign(top, height, textHeight, verticalAlign),
        originX,
        originY: 'top',
    };
}

export function clickToAlignedPlacement(
    clickX: number,
    clickY: number,
    boxWidth: number,
    boxHeight: number,
    canvasWidth: number,
    canvasHeight: number,
    verticalAlign: ClickVerticalAlign = 'baseline',
): PixelRect {
    const width = Math.min(Math.max(10, boxWidth), canvasWidth);
    const height = Math.min(Math.max(10, boxHeight), canvasHeight);
    const maxLeft = Math.max(0, canvasWidth - width);
    const maxTop = Math.max(0, canvasHeight - height);
    const left = clamp(clickX - width / 2, 0, maxLeft);
    let top = clickY - height / 2;

    if (verticalAlign === 'top') {
        top = clickY;
    } else if (verticalAlign === 'baseline') {
        top = clickY - height;
    }

    return {
        left: Math.round(left),
        top: Math.round(clamp(top, 0, maxTop)),
        width: Math.round(width),
        height: Math.round(height),
    };
}

export function clickToCenteredPlacement(
    clickX: number,
    clickY: number,
    boxWidth: number,
    boxHeight: number,
    canvasWidth: number,
    canvasHeight: number,
): PixelRect {
    return clickToAlignedPlacement(
        clickX,
        clickY,
        boxWidth,
        boxHeight,
        canvasWidth,
        canvasHeight,
        'middle',
    );
}

export function nudgeNormalizedPlacement(
    placement: NormalizedRect,
    deltaXPx: number,
    deltaYPx: number,
    canvasWidth: number,
    canvasHeight: number,
): NormalizedRect {
    if (canvasWidth <= 0 || canvasHeight <= 0) {
        return placement;
    }

    const x = clamp(
        placement.x + deltaXPx / canvasWidth,
        0,
        Math.max(0, 1 - placement.width),
    );
    const y = clamp(
        placement.y + deltaYPx / canvasHeight,
        0,
        Math.max(0, 1 - placement.height),
    );

    return {
        x: Number(x.toFixed(6)),
        y: Number(y.toFixed(6)),
        width: placement.width,
        height: placement.height,
    };
}

export function nudgeDeltaFromKeyboard(event: KeyboardEvent): {
    dx: number;
    dy: number;
} | null {
    if (event.metaKey || event.ctrlKey || event.altKey) {
        return null;
    }

    const step = event.shiftKey ? 10 : 1;

    if (event.key === 'ArrowLeft') {
        return { dx: -step, dy: 0 };
    }

    if (event.key === 'ArrowRight') {
        return { dx: step, dy: 0 };
    }

    if (event.key === 'ArrowUp') {
        return { dx: 0, dy: -step };
    }

    if (event.key === 'ArrowDown') {
        return { dx: 0, dy: step };
    }

    return null;
}

export function isEditableTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return (
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        target.isContentEditable
    );
}

export function isUndoKey(event: KeyboardEvent): boolean {
    const modifier = event.metaKey || event.ctrlKey;

    return (
        modifier &&
        !event.shiftKey &&
        !event.altKey &&
        event.key.toLowerCase() === 'z'
    );
}

export function isRedoKey(event: KeyboardEvent): boolean {
    const modifier = event.metaKey || event.ctrlKey;

    if (!modifier || event.altKey) {
        return false;
    }

    if (event.key.toLowerCase() === 'y') {
        return true;
    }

    return event.shiftKey && event.key.toLowerCase() === 'z';
}
