export type SignaturePlacementConfig = {
    page: number;
    overlay: {
        left: string;
        top: string;
        width: string;
        height: string;
    };
    stamps: Array<{
        type: string;
        x: number;
        y: number;
        w?: number;
        h?: number;
    }>;
};

export type EditorRect = {
    left: number;
    top: number;
    width: number;
    height: number;
};

export type EditorPlacementRects = {
    signature: EditorRect;
    date: EditorRect;
    signature_ar: EditorRect;
    date_ar: EditorRect;
};

const PAGE_WIDTH_MM = 210;
const PAGE_HEIGHT_MM = 297;

function fromPercent(value: string, total: number): number {
    const numeric = Number.parseFloat(value.replace('%', '').trim());

    return (numeric / 100) * total;
}

function fromMmX(mm: number, canvasWidth: number): number {
    return (mm / PAGE_WIDTH_MM) * canvasWidth;
}

function fromMmY(mm: number, canvasHeight: number): number {
    return (mm / PAGE_HEIGHT_MM) * canvasHeight;
}

function toMmX(pixels: number, canvasWidth: number): number {
    return (pixels / canvasWidth) * PAGE_WIDTH_MM;
}

function imageStampToRect(
    stamp: SignaturePlacementConfig['stamps'][number],
    canvasWidth: number,
    canvasHeight: number,
): EditorRect {
    return {
        left: fromMmX(stamp.x, canvasWidth),
        top: fromMmY(stamp.y, canvasHeight),
        width: fromMmX(stamp.w ?? 0, canvasWidth),
        height: fromMmY(stamp.h ?? 0, canvasHeight),
    };
}

function dateStampToRect(
    stamp: SignaturePlacementConfig['stamps'][number],
    defaultWidth: number,
    defaultHeight: number,
    canvasWidth: number,
    canvasHeight: number,
): EditorRect {
    const dateLeft = fromMmX(stamp.x, canvasWidth);
    const dateBottom = fromMmY(stamp.y, canvasHeight);

    return {
        left: dateLeft,
        top: Math.max(0, dateBottom - defaultHeight),
        width: defaultWidth,
        height: defaultHeight,
    };
}

function mirrorRect(rect: EditorRect, canvasWidth: number): EditorRect {
    const widthMm = toMmX(rect.width, canvasWidth);
    const leftMm = toMmX(rect.left, canvasWidth);
    const mirroredLeftMm = PAGE_WIDTH_MM - leftMm - widthMm;

    return {
        left: fromMmX(mirroredLeftMm, canvasWidth),
        top: rect.top,
        width: rect.width,
        height: rect.height,
    };
}

export function scalePlacementRects(
    rects: EditorPlacementRects,
    fromWidth: number,
    fromHeight: number,
    toWidth: number,
    toHeight: number,
): EditorPlacementRects {
    const scaleRect = (rect: EditorRect): EditorRect => ({
        left: (rect.left / fromWidth) * toWidth,
        top: (rect.top / fromHeight) * toHeight,
        width: (rect.width / fromWidth) * toWidth,
        height: (rect.height / fromHeight) * toHeight,
    });

    return {
        signature: scaleRect(rects.signature),
        date: scaleRect(rects.date),
        signature_ar: scaleRect(rects.signature_ar),
        date_ar: scaleRect(rects.date_ar),
    };
}

export function editorRectsFromConfig(
    config: SignaturePlacementConfig,
    canvasWidth: number,
    canvasHeight: number,
): EditorPlacementRects {
    const overlay = config.overlay;
    const signature: EditorRect = {
        left: fromPercent(overlay.left, canvasWidth),
        top: fromPercent(overlay.top, canvasHeight),
        width: fromPercent(overlay.width, canvasWidth),
        height: fromPercent(overlay.height, canvasHeight),
    };

    const imageStamps = config.stamps.filter((stamp) => stamp.type === 'image');
    const dateStamps = config.stamps.filter((stamp) => stamp.type === 'date');

    const defaultDateWidth = Math.max(signature.width * 0.6, 40);
    const defaultDateHeight = Math.max(signature.height * 0.5, 16);

    const signatureAr = imageStamps[1]
        ? imageStampToRect(imageStamps[1], canvasWidth, canvasHeight)
        : mirrorRect(signature, canvasWidth);

    const date = dateStamps[0]
        ? dateStampToRect(
              dateStamps[0],
              defaultDateWidth,
              defaultDateHeight,
              canvasWidth,
              canvasHeight,
          )
        : {
              left: signature.left,
              top: signature.top + signature.height + 8,
              width: defaultDateWidth,
              height: defaultDateHeight,
          };

    const dateAr = dateStamps[1]
        ? dateStampToRect(
              dateStamps[1],
              defaultDateWidth,
              defaultDateHeight,
              canvasWidth,
              canvasHeight,
          )
        : mirrorRect(date, canvasWidth);

    return {
        signature,
        date,
        signature_ar: signatureAr,
        date_ar: dateAr,
    };
}
