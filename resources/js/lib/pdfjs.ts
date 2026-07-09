import type * as PdfJs from 'pdfjs-dist';

let pdfjsModule: typeof PdfJs | null = null;

export async function getPdfJs(): Promise<typeof PdfJs> {
    if (typeof window === 'undefined') {
        throw new Error('PDF.js is only available in the browser.');
    }

    if (!pdfjsModule) {
        const [pdfjs, workerModule] = await Promise.all([
            import('pdfjs-dist'),
            import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
        ]);

        pdfjs.GlobalWorkerOptions.workerSrc = workerModule.default;
        pdfjsModule = pdfjs;
    }

    return pdfjsModule;
}
