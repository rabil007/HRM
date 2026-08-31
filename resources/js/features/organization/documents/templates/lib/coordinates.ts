export type PixelRect = {
    left: number;
    top: number;
    width: number;
    height: number;
};

export type NormalizedRect = {
    x: number;
    y: number;
    width: number;
    height: number;
};

export function clamp(value: number, min: number, max: number): number {
    return Math.max(min, Math.min(max, value));
}

export type ViewportRect = {
    top: number;
    left: number;
    width: number;
    height: number;
    bottom: number;
    right: number;
};

/**
 * Place a box in the center of the canvas region currently visible in the
 * scroll container, clamped so it stays on the page.
 */
export function placementRectInVisibleCanvas({
    canvasWidth,
    canvasHeight,
    boxWidth,
    boxHeight,
    canvasRect,
    viewRect,
}: {
    canvasWidth: number;
    canvasHeight: number;
    boxWidth: number;
    boxHeight: number;
    canvasRect: ViewportRect;
    viewRect: ViewportRect;
}): PixelRect {
    const width = Math.min(Math.max(10, boxWidth), canvasWidth);
    const height = Math.min(Math.max(10, boxHeight), canvasHeight);

    if (
        canvasWidth <= 0 ||
        canvasHeight <= 0 ||
        canvasRect.width <= 0 ||
        canvasRect.height <= 0
    ) {
        return {
            left: Math.round((canvasWidth - width) / 2),
            top: Math.round((canvasHeight - height) / 2),
            width: Math.round(width),
            height: Math.round(height),
        };
    }

    const scaleX = canvasWidth / canvasRect.width;
    const scaleY = canvasHeight / canvasRect.height;
    const overlapTop = Math.max(canvasRect.top, viewRect.top);
    const overlapBottom = Math.min(canvasRect.bottom, viewRect.bottom);
    const overlapLeft = Math.max(canvasRect.left, viewRect.left);
    const overlapRight = Math.min(canvasRect.right, viewRect.right);

    if (overlapBottom <= overlapTop || overlapRight <= overlapLeft) {
        return {
            left: Math.round((canvasWidth - width) / 2),
            top: Math.round((canvasHeight - height) / 2),
            width: Math.round(width),
            height: Math.round(height),
        };
    }

    const visibleTop = (overlapTop - canvasRect.top) * scaleY;
    const visibleBottom = (overlapBottom - canvasRect.top) * scaleY;
    const visibleLeft = (overlapLeft - canvasRect.left) * scaleX;
    const visibleRight = (overlapRight - canvasRect.left) * scaleX;
    const visibleHeight = visibleBottom - visibleTop;
    const visibleWidth = visibleRight - visibleLeft;

    const left = clamp(visibleLeft + (visibleWidth - width) / 2, 0, canvasWidth - width);
    const top = clamp(visibleTop + (visibleHeight - height) / 2, 0, canvasHeight - height);

    return {
        left: Math.round(left),
        top: Math.round(top),
        width: Math.round(width),
        height: Math.round(height),
    };
}

/**
 * Convert pixel coordinates on a canvas to normalized [0.0, 1.0] coordinates.
 */
export function pixelToNormalized(
    pixel: PixelRect,
    canvasWidth: number,
    canvasHeight: number,
): NormalizedRect {
    if (canvasWidth <= 0 || canvasHeight <= 0) {
        return { x: 0, y: 0, width: 0, height: 0 };
    }

    const x = clamp(pixel.left / canvasWidth, 0, 1);
    const y = clamp(pixel.top / canvasHeight, 0, 1);
    const width = clamp(pixel.width / canvasWidth, 0.0001, 1 - x);
    const height = clamp(pixel.height / canvasHeight, 0.0001, 1 - y);

    return {
        x: Number(x.toFixed(6)),
        y: Number(y.toFixed(6)),
        width: Number(width.toFixed(6)),
        height: Number(height.toFixed(6)),
    };
}

/**
 * Convert normalized [0.0, 1.0] coordinates to canvas pixel coordinates.
 */
export function normalizedToPixel(
    normalized: NormalizedRect,
    canvasWidth: number,
    canvasHeight: number,
): PixelRect {
    const left = normalized.x * canvasWidth;
    const top = normalized.y * canvasHeight;
    const width = normalized.width * canvasWidth;
    const height = normalized.height * canvasHeight;

    return {
        left: Math.round(left),
        top: Math.round(top),
        width: Math.max(10, Math.round(width)),
        height: Math.max(10, Math.round(height)),
    };
}
