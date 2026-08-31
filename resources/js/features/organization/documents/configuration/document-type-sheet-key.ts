export function documentTypeSheetKey(
    openDocumentTypeId: number | null | undefined,
): string {
    if (openDocumentTypeId == null || openDocumentTypeId <= 0) {
        return 'list';
    }

    return String(openDocumentTypeId);
}
