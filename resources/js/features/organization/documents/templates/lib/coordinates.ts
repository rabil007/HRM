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

export const DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX = 160;
export const DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX = 26;

export type OverlayPlacementChromeMode = 'edit' | 'print';

export function overlayPlacementChrome(mode: OverlayPlacementChromeMode): {
    fill: string;
    stroke: string;
} {
    return mode === 'print'
        ? { fill: 'transparent', stroke: 'transparent' }
        : { fill: 'rgba(59,130,246,0.08)', stroke: '#93c5fd' };
}

export type OverlayPlacementBoxChrome = {
    fill: string;
    stroke: string;
    strokeWidth: number;
};

/**
 * Canvas chrome for field/text boxes. Only boxes that cannot fit even at 8pt
 * get a loud highlight. Automatic font shrink stays silent — the drawn box is
 * already the max size.
 */
export function overlayPlacementBoxChrome(
    mode: OverlayPlacementChromeMode,
    overflow: 'ok' | 'shrink' | 'fail' = 'ok',
): OverlayPlacementBoxChrome {
    if (mode === 'print') {
        return { fill: 'transparent', stroke: 'transparent', strokeWidth: 0 };
    }

    const alert = overlayPlacementAlertStyle(overflow);

    if (alert) {
        return alert;
    }

    const chrome = overlayPlacementChrome('edit');

    return { fill: chrome.fill, stroke: chrome.stroke, strokeWidth: 1 };
}

export function overlayPlacementTextFill(
    mode: OverlayPlacementChromeMode,
    color: string,
): string {
    return color;
}

export function overlayPlacementAlertStyle(
    level: 'ok' | 'shrink' | 'fail',
): OverlayPlacementBoxChrome | null {
    if (level === 'fail') {
        return {
            fill: 'rgba(220,38,38,0.22)',
            stroke: '#dc2626',
            strokeWidth: 2.5,
        };
    }

    return null;
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

    const left = clamp(
        visibleLeft + (visibleWidth - width) / 2,
        0,
        canvasWidth - width,
    );
    const top = clamp(
        visibleTop + (visibleHeight - height) / 2,
        0,
        canvasHeight - height,
    );

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

export type FabricRectLike = {
    left?: number | null;
    top?: number | null;
    width?: number | null;
    height?: number | null;
    scaleX?: number | null;
    scaleY?: number | null;
    originX?: string | number;
    originY?: string | number;
};

/**
 * Geometric box of a Fabric object, excluding stroke and selection chrome.
 * Signature save must use this — getBoundingRect() includes the outline and
 * drifts on every drag/save.
 */
export function fabricObjectToPixelRect(object: FabricRectLike): PixelRect {
    const width = Math.max(1, (object.width ?? 0) * (object.scaleX ?? 1));
    const height = Math.max(1, (object.height ?? 0) * (object.scaleY ?? 1));
    let left = object.left ?? 0;
    let top = object.top ?? 0;

    if (object.originX === 'center') {
        left -= width / 2;
    } else if (object.originX === 'right') {
        left -= width;
    }

    if (object.originY === 'center') {
        top -= height / 2;
    } else if (object.originY === 'bottom') {
        top -= height;
    }

    return { left, top, width, height };
}

export const OVERLAY_FONT_SIZE_MIN_PT = 8;
export const OVERLAY_FONT_SIZE_MAX_PT = 48;
export const OVERLAY_FONT_SIZE_PRESETS_PT = [
    8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 32, 36, 48,
] as const;

export function clampOverlayFontSizePt(value: number | undefined): number {
    const points =
        typeof value === 'number' && Number.isFinite(value) && value > 0
            ? Math.round(value)
            : 12;

    return clamp(points, OVERLAY_FONT_SIZE_MIN_PT, OVERLAY_FONT_SIZE_MAX_PT);
}

export function stepOverlayFontSizePt(
    current: number | undefined,
    delta: number,
): number {
    return clampOverlayFontSizePt(clampOverlayFontSizePt(current) + delta);
}

export function overlayFontSizeSelectOptionsPt(
    current: number | undefined,
): number[] {
    const size = clampOverlayFontSizePt(current);

    if ((OVERLAY_FONT_SIZE_PRESETS_PT as readonly number[]).includes(size)) {
        return [...OVERLAY_FONT_SIZE_PRESETS_PT];
    }

    return [...OVERLAY_FONT_SIZE_PRESETS_PT, size].sort((a, b) => a - b);
}

/**
 * Convert a stored overlay font size (PDF points) to Fabric/canvas pixels.
 *
 * PDF.js `getViewport({ scale: 1 })` uses 1 PDF point = 1 CSS pixel. The
 * designer then scales the page to a target width, so 12pt must be drawn at
 * `12 * viewportScale` or the overlay looks smaller and uneven next to the
 * source letter.
 */
export function overlayFontSizePx(
    fontSizePt: number | undefined,
    pdfScale: number,
): number {
    const points = fontSizePt && fontSizePt > 0 ? fontSizePt : 12;
    const scale = pdfScale > 0 ? pdfScale : 1;

    return points * scale;
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
