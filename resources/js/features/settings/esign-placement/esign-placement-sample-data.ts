export function sampleSignatureDataUrl(): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 64">
        <path d="M12 42 C36 8, 58 52, 82 28 S128 12, 156 34 S188 22, 206 30" fill="none" stroke="#1a1a1a" stroke-width="2.5" stroke-linecap="round"/>
    </svg>`;

    return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

/** Matches FPDI stamp format in StampSignedBulkDocumentPdf (`d M Y`). */
export function sampleSignedDate(referenceDate: Date = new Date()): string {
    const day = referenceDate.getDate().toString().padStart(2, '0');
    const month = referenceDate.toLocaleString('en-GB', { month: 'short' });
    const year = referenceDate.getFullYear();

    return `${day} ${month} ${year}`;
}
