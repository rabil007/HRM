export function signedPdfViewUrl(requestId: number): string {
    return `/organization/documents/bulk/signatures/${requestId}/download?inline=1`;
}

export function signedPdfDownloadUrl(requestId: number): string {
    return `/organization/documents/bulk/signatures/${requestId}/download`;
}

export function employeeDocumentViewUrl(documentId: number): string {
    return `/organization/documents/files/${documentId}/download?inline=1`;
}

export function employeeDocumentDownloadUrl(documentId: number): string {
    return `/organization/documents/files/${documentId}/download`;
}
