export function shouldRenderPlatformAiSection(platformAi: unknown): boolean {
    return platformAi !== null && platformAi !== undefined;
}

export function shouldRenderDocumentAiSection(documentAi: unknown): boolean {
    return documentAi !== null && documentAi !== undefined;
}
