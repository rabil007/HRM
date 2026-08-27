export function insertMergeField(
    content: string,
    fieldKey: string,
    selection?: { start: number; end: number } | null,
): { newContent: string; newCursorPosition: number } {
    if (!selection || selection.start < 0 || selection.end < 0) {
        const spacer =
            content.length > 0 &&
            !content.endsWith(' ') &&
            !content.endsWith('\n')
                ? ' '
                : '';
        const newContent = content + spacer + fieldKey;

        return {
            newContent,
            newCursorPosition: newContent.length,
        };
    }

    const before = content.slice(0, selection.start);
    const after = content.slice(selection.end);
    const newContent = before + fieldKey + after;
    const newCursorPosition = before.length + fieldKey.length;

    return {
        newContent,
        newCursorPosition,
    };
}
