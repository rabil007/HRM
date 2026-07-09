import type * as PdfJs from 'pdfjs-dist';

let pdfjsModule: typeof PdfJs | null = null;

export async function getPdfJs(): Promise<typeof PdfJs> {
    if (typeof window === 'undefined') {
        throw new Error('PDF.js is only available in the browser.');
    }

    if (!pdfjsModule) {
        const pdfjs = await import('pdfjs-dist');

        pdfjs.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js';
        pdfjsModule = pdfjs;
    }

    return pdfjsModule;
}
