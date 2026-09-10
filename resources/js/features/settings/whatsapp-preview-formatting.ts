export type WhatsAppPreviewToken = {
    type: 'text' | 'bold' | 'italic' | 'link';
    value: string;
};

/**
 * Preview-only tokenizer for Meta template shells.
 * Supports URLs plus minimal WhatsApp *bold* / _italic_ markers.
 * Does not change Meta payload construction.
 */
export function tokenizeWhatsAppPreviewBody(
    bodyText: string,
): WhatsAppPreviewToken[] {
    const tokens: WhatsAppPreviewToken[] = [];
    const urlParts = bodyText.split(/(https?:\/\/[^\s]+)/g);

    for (const part of urlParts) {
        if (part === '') {
            continue;
        }

        if (/^https?:\/\/[^\s]+$/.test(part)) {
            tokens.push({ type: 'link', value: part });
            continue;
        }

        tokens.push(...tokenizeWhatsAppPreviewFormatting(part));
    }

    return tokens;
}

export function tokenizeWhatsAppPreviewFormatting(
    text: string,
): WhatsAppPreviewToken[] {
    if (text === '') {
        return [];
    }

    const tokens: WhatsAppPreviewToken[] = [];
    const pattern = /(\*[^*\n]+\*|_[^_\n]+_)/g;
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            tokens.push({
                type: 'text',
                value: text.slice(lastIndex, match.index),
            });
        }

        const token = match[0];
        const inner = token.slice(1, -1);

        tokens.push({
            type: token.startsWith('*') ? 'bold' : 'italic',
            value: inner,
        });

        lastIndex = match.index + token.length;
    }

    if (lastIndex < text.length) {
        tokens.push({ type: 'text', value: text.slice(lastIndex) });
    }

    return tokens;
}
