import * as pdfjs from 'pdfjs-dist';
import pdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

import type { PdfPreviewData } from '@/features/organization/documents/pdf-merge/types';

pdfjs.GlobalWorkerOptions.workerSrc = pdfjsWorker;

const previewCache = new Map<number, PdfPreviewData>();
const loadingPromises = new Map<number, Promise<PdfPreviewData>>();

const THUMBNAIL_SCALE = 0.5;

async function renderFirstPageThumbnail(
    pdf: pdfjs.PDFDocumentProxy,
): Promise<string | null> {
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

export async function loadPdfPreview(
    documentId: number,
    fileUrl: string,
): Promise<PdfPreviewData> {
    const cached = previewCache.get(documentId);

    if (cached) {
        return cached;
    }

    const pending = loadingPromises.get(documentId);

    if (pending) {
        return pending;
    }

    const promise = (async (): Promise<PdfPreviewData> => {
        const pdf = await pdfjs.getDocument(fileUrl).promise;
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
