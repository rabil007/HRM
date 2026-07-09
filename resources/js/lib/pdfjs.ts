import type * as PdfJs from 'pdfjs-dist';

let pdfjsModule: typeof PdfJs | null = null;

export async function getPdfJs(): Promise<typeof PdfJs> {
    if (typeof window === 'undefined') {
        throw new Error('PDF.js is only available in the browser.');
    }

    if (!pdfjsModule) {
        const pdfjs = await import('pdfjs-dist');

        // #region agent log
        fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'aa4780'},body:JSON.stringify({sessionId:'aa4780',location:'pdfjs.ts:getPdfJs',message:'PDF.js worker initialized',data:{workerSrc:'/pdf.worker.min.js'},timestamp:Date.now(),hypothesisId:'A',runId:'post-fix'})}).catch(()=>{});
        // #endregion

        pdfjs.GlobalWorkerOptions.workerSrc = '/pdf.worker.min.js';
        pdfjsModule = pdfjs;
    }

    return pdfjsModule;
}
