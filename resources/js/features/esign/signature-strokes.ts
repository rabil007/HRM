export type SignaturePoint = {
    x: number;
    y: number;
};

export type SignatureStroke = SignaturePoint[];

export function commitStroke(
    strokes: SignatureStroke[],
    stroke: SignatureStroke | null,
): SignatureStroke[] {
    if (stroke === null || stroke.length === 0) {
        return strokes;
    }

    return [...strokes, stroke];
}

export function undoLastStroke(strokes: SignatureStroke[]): SignatureStroke[] {
    if (strokes.length === 0) {
        return strokes;
    }

    return strokes.slice(0, -1);
}

export function signaturePayloadFromStrokes(
    strokes: SignatureStroke[],
    toDataUrl: () => string,
): string | null {
    return strokes.length === 0 ? null : toDataUrl();
}
