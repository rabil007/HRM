export type OverflowLevel = 'ok' | 'shrink' | 'fail';

export const MIN_OVERLAY_FONT_SIZE_PT = 8;

export const LONG_NAME_OVERFLOW_SAMPLE =
    'Mohammed Abdullah Al-Rashid Al-Maktoum';

export const LONG_NAME_OVERFLOW_FIELDS = [
    '{{employee_name}}',
    '{{first_name}}',
    '{{last_name}}',
    '{{manager_name}}',
] as const;

const OVERFLOW_TOLERANCE_PX = 1;

export function overflowLevelFromWidth(
    measuredWidthPx: number,
    boxWidthPx: number,
    requestedPt: number,
    minPt = MIN_OVERLAY_FONT_SIZE_PT,
): OverflowLevel {
    if (boxWidthPx <= 0 || requestedPt <= 0) {
        return 'fail';
    }

    if (measuredWidthPx <= boxWidthPx + OVERFLOW_TOLERANCE_PX) {
        return 'ok';
    }

    const neededPt = requestedPt * (boxWidthPx / measuredWidthPx);

    return neededPt + 0.001 >= minPt ? 'shrink' : 'fail';
}

export function overflowLevelFromWrappedBox(
    measuredWidthAtRequestedPx: number,
    boxWidthPx: number,
    boxHeightPx: number,
    requestedPt: number,
    fontSizePx: number,
    lineHeight = 1.2,
    minPt = MIN_OVERLAY_FONT_SIZE_PT,
): OverflowLevel {
    if (boxWidthPx <= 0 || boxHeightPx <= 0 || requestedPt <= 0) {
        return 'fail';
    }

    const heightAt = (sizePt: number): number => {
        const widthAtSize = measuredWidthAtRequestedPx * (sizePt / requestedPt);
        const lines = Math.max(1, Math.ceil(widthAtSize / boxWidthPx));
        const sizePx = fontSizePx * (sizePt / requestedPt);

        return lines * sizePx * lineHeight;
    };

    if (heightAt(requestedPt) <= boxHeightPx + OVERFLOW_TOLERANCE_PX) {
        return 'ok';
    }

    return heightAt(minPt) <= boxHeightPx + OVERFLOW_TOLERANCE_PX
        ? 'shrink'
        : 'fail';
}

export function measureTextWidthPx(
    text: string,
    fontPx: number,
    fontFamily: string,
    fontWeight: string,
): number {
    if (text === '' || fontPx <= 0) {
        return 0;
    }

    if (typeof document === 'undefined') {
        return text.length * fontPx * 0.5;
    }

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context) {
        return text.length * fontPx * 0.5;
    }

    context.font = `${fontWeight} ${fontPx}px ${fontFamily}`;

    return context.measureText(text).width;
}

export function isLongNameOverflowField(fieldKey: string): boolean {
    return (LONG_NAME_OVERFLOW_FIELDS as readonly string[]).includes(fieldKey);
}

export function overflowPreviewText(
    fieldKey: string,
    catalogSample: string,
    employeeValue?: string,
): string {
    if (employeeValue !== undefined && employeeValue !== '') {
        return employeeValue;
    }

    if (isLongNameOverflowField(fieldKey)) {
        return LONG_NAME_OVERFLOW_SAMPLE;
    }

    return catalogSample;
}

/**
 * Text painted inside a merge-field box on the designer canvas.
 * Uses sample/employee values so labels already printed on the PDF are not repeated.
 */
export function overlayFieldCanvasText(
    catalogSample: string | undefined,
    employeeValue?: string | null,
): string {
    const employee = employeeValue?.trim();

    if (employee) {
        return employee;
    }

    return catalogSample?.trim() ?? '';
}

export function placementOverflowLabel(
    type: 'field' | 'text',
    value: string,
    fieldLabel?: string,
): string {
    if (type === 'field') {
        const label = fieldLabel?.trim();

        return label !== undefined && label !== '' ? label : value;
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return 'Text box';
    }

    return trimmed.length > 32 ? `${trimmed.slice(0, 31)}…` : trimmed;
}

export function summarizeOverflowLabels(labels: string[]): string {
    const unique: string[] = [];

    for (const label of labels) {
        if (!unique.includes(label)) {
            unique.push(label);
        }
    }

    return unique.join(', ');
}

export function overflowPageBanner(
    failLabels: string[],
): { tone: 'fail'; message: string } | null {
    if (failLabels.length === 0) {
        return null;
    }

    const named = summarizeOverflowLabels(failLabels);
    const n = failLabels.length;

    return {
        tone: 'fail',
        message:
            n === 1
                ? `${named} is too small for the text. Drag the box bigger.`
                : `These boxes are too small for the text: ${named}. Drag them bigger.`,
    };
}

export function estimatePlacementOverflow({
    text,
    boxWidthPx,
    boxHeightPx,
    requestedPt,
    fontSizePx,
    fontFamily,
    fontWeight,
    wrap,
}: {
    text: string;
    boxWidthPx: number;
    boxHeightPx: number;
    requestedPt: number;
    fontSizePx: number;
    fontFamily: string;
    fontWeight: string;
    wrap: boolean;
}): OverflowLevel {
    if (text.trim() === '') {
        return 'ok';
    }

    const measured = measureTextWidthPx(
        text,
        fontSizePx,
        fontFamily,
        fontWeight,
    );

    if (wrap) {
        return overflowLevelFromWrappedBox(
            measured,
            boxWidthPx,
            boxHeightPx,
            requestedPt,
            fontSizePx,
        );
    }

    return overflowLevelFromWidth(measured, boxWidthPx, requestedPt);
}
