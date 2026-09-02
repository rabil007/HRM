export function pdfEmbedUrl(fileUrl: string): string {
    const hashIndex = fileUrl.indexOf('#');

    if (hashIndex !== -1) {
        return fileUrl;
    }

    return `${fileUrl}#toolbar=0&navpanes=0&scrollbar=0`;
}
