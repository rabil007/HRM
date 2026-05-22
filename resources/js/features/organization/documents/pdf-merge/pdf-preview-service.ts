import type * as PdfJs from 'pdfjs-dist';
import type { PDFDocumentProxy } from 'pdfjs-dist';

import type { PdfPreviewData } from '@/features/organization/documents/pdf-merge/types';
import { documents } from '@/routes/organization';

const previewCache = new Map<number, PdfPreviewData>();
const loadingPromises = new Map<number, Promise<PdfPreviewData>>();

const THUMBNAIL_SCALE = 0.35;

let pdfjsModule: typeof PdfJs | null = null;

async function getPdfJs(): Promise<typeof PdfJs> {
    if (typeof window === 'undefined') {
        throw new Error('PDF preview is only available in the browser.');
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

async function fetchPdfBytes(documentId: number): Promise<ArrayBuffer> {
    const response = await fetch(documents.files.download.url({ document: documentId }), {
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error('Failed to load PDF.');
    }

    return response.arrayBuffer();
}

async function renderFirstPageThumbnail(pdf: PDFDocumentProxy): Promise<string | null> {
    try {
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: THUMBNAIL_SCALE });
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');

        if (!context) {
            return null;
        }

        canvas.width = viewport.width;
        canvas.height = viewport.height;

        await page.render({ canvasContext: context, viewport, canvas }).promise;

        return canvas.toDataURL('image/jpeg', 0.72);
    } catch {
        return null;
    }
}

export async function loadPdfPreview(documentId: number): Promise<PdfPreviewData> {
    const cached = previewCache.get(documentId);

    if (cached) {
        return cached;
    }

    const pending = loadingPromises.get(documentId);

    if (pending) {
        return pending;
    }

    const promise = (async (): Promise<PdfPreviewData> => {
        const pdfjs = await getPdfJs();
        const data = await fetchPdfBytes(documentId);
        const pdf = await pdfjs.getDocument({ data }).promise;
        const thumbnailDataUrl = await renderFirstPageThumbnail(pdf);
        const result: PdfPreviewData = {
            pageCount: pdf.numPages,
            thumbnailDataUrl,
        };

        previewCache.set(documentId, result);
        loadingPromises.delete(documentId);

        return result;
    })().catch((error: unknown) => {
        loadingPromises.delete(documentId);

        throw error;
    });

    loadingPromises.set(documentId, promise);

    return promise;
}

export function clearPdfPreviewCache(): void {
    previewCache.clear();
    loadingPromises.clear();
}
