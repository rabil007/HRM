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
