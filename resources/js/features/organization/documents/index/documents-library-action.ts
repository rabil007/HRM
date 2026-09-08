export type DocumentsLibraryAction = 'upload' | 'share' | 'download' | null;

export function documentsLibraryActionMessage(
    action: DocumentsLibraryAction,
): string | null {
    if (action === 'upload') {
        return 'Search for an employee, then open their folder. The upload form will open automatically.';
    }

    if (action === 'share') {
        return 'Select one or more employee folders, then choose Share links from the selection bar.';
    }

    if (action === 'download') {
        return 'Select one or more employee folders, then choose Download ZIP from the selection bar.';
    }

    return null;
}

export function shouldOpenLibraryUpload(url: string): boolean {
    const query = url.split('?')[1]?.split('#')[0] ?? '';

    return new URLSearchParams(query).get('action') === 'upload';
}
