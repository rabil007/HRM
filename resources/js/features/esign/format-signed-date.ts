/** Matches FPDI stamp format in StampSignedBulkDocumentPdf (`d M Y`). */
export function formatSignedDate(referenceDate: Date = new Date()): string {
    const day = referenceDate.getDate().toString().padStart(2, '0');
    const month = referenceDate.toLocaleString('en-GB', { month: 'short' });
    const year = referenceDate.getFullYear();

    return `${day} ${month} ${year}`;
}
