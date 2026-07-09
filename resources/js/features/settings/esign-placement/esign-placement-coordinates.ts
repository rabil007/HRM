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

export function editorRectsFromConfig(
    config: SignaturePlacementConfig,
    canvasWidth: number,
    canvasHeight: number,
): { signature: EditorRect; date: EditorRect } {
    const overlay = config.overlay;
    const signature: EditorRect = {
        left: fromPercent(overlay.left, canvasWidth),
        top: fromPercent(overlay.top, canvasHeight),
        width: fromPercent(overlay.width, canvasWidth),
        height: fromPercent(overlay.height, canvasHeight),
    };

    const enDate = config.stamps.find((stamp) => stamp.type === 'date');
    const dateWidth = Math.max(signature.width * 0.6, 40);
    const dateHeight = Math.max(signature.height * 0.5, 16);

    if (!enDate) {
        return {
            signature,
            date: {
                left: signature.left,
                top: signature.top + signature.height + 8,
                width: dateWidth,
                height: dateHeight,
            },
        };
    }

    const dateLeft = fromMmX(enDate.x, canvasWidth);
    const dateBottom = fromMmY(enDate.y, canvasHeight);

    return {
        signature,
        date: {
            left: dateLeft,
            top: Math.max(0, dateBottom - dateHeight),
            width: dateWidth,
            height: dateHeight,
        },
    };
}
