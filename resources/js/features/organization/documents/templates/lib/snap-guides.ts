export const SNAP_THRESHOLD_PX = 6;

export type SnapGuide = {
    axis: 'y';
    position: number;
};

export type SnapBox = {
    left: number;
    top: number;
    width: number;
    height: number;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function uniquePositions(values: number[]): number[] {
    const seen = new Set<number>();
    const unique: number[] = [];

    for (const value of values) {
        if (!Number.isFinite(value)) {
            continue;
        }

        const key = Number(value.toFixed(2));

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(value);
    }

    return unique;
}

function closestDelta(
    movingLines: number[],
    targets: number[],
    threshold: number,
): { delta: number; target: number } | null {
    let best: { delta: number; target: number } | null = null;

    for (const line of movingLines) {
        for (const target of targets) {
            const delta = target - line;

            if (Math.abs(delta) > threshold) {
                continue;
            }

            if (best === null || Math.abs(delta) < Math.abs(best.delta)) {
                best = { delta, target };
            }
        }
    }

    return best;
}

function horizontalLines(box: SnapBox): number[] {
    return [box.top, box.top + box.height / 2, box.top + box.height];
}

export function pageYGuidePositions(canvasHeight: number): number[] {
    return uniquePositions([0, canvasHeight / 2, canvasHeight]);
}

/**
 * Snap only on the Y axis (top / middle / baseline) so boxes sit on the same
 * printed line. Left/right is left to the user (Width % / drag).
 */
export function snapRectToGuides(
    moving: SnapBox,
    others: SnapBox[],
    canvasWidth: number,
    canvasHeight: number,
    threshold = SNAP_THRESHOLD_PX,
): { left: number; top: number; guides: SnapGuide[] } {
    const yTargets = [...pageYGuidePositions(canvasHeight)];

    for (const other of others) {
        yTargets.push(...horizontalLines(other));
    }

    const uniqueY = uniquePositions(yTargets);
    const snapY = closestDelta(horizontalLines(moving), uniqueY, threshold);
    const maxLeft = Math.max(0, canvasWidth - moving.width);
    const maxTop = Math.max(0, canvasHeight - moving.height);
    const left = clamp(moving.left, 0, maxLeft);
    const top = clamp(moving.top + (snapY?.delta ?? 0), 0, maxTop);
    const snappedLines = horizontalLines({ ...moving, left, top });
    const guides: SnapGuide[] = [];
    const seenY = new Set<number>();

    for (const line of snappedLines) {
        for (const target of uniqueY) {
            const key = Number(target.toFixed(2));

            if (Math.abs(line - target) > 0.51 || seenY.has(key)) {
                continue;
            }

            seenY.add(key);
            guides.push({ axis: 'y', position: target });
        }
    }

    return { left, top, guides };
}
