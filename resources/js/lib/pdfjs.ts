import type * as PdfJs from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

let pdfjsModule: typeof PdfJs | null = null;

export async function getPdfJs(): Promise<typeof PdfJs> {
    if (typeof window === 'undefined') {
        throw new Error('PDF.js is only available in the browser.');
    }

    if (!pdfjsModule) {
        const pdfjs =
            (await import('pdfjs-dist/legacy/build/pdf.mjs')) as typeof PdfJs;

        pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
        pdfjsModule = pdfjs;
    }

    return pdfjsModule;
}
