import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { EditorPlacementRects } from '@/features/settings/esign-placement/esign-placement-coordinates';
import { scalePlacementRects } from '@/features/settings/esign-placement/esign-placement-coordinates';
import {
    sampleSignatureDataUrl,
    sampleSignedDate,
} from '@/features/settings/esign-placement/esign-placement-sample-data';
import { getPdfJs } from '@/lib/pdfjs';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    pdfUrl: string;
    rects: EditorPlacementRects | null;
    sourceCanvasSize?: { width: number; height: number };
    page?: number;
};

type OverlaySpec = {
    key: string;
    label: string;
    rect: { left: number; top: number; width: number; height: number };
    kind: 'signature' | 'date';
};

export function EsignPlacementSamplePreviewDialog({
    open,
    onOpenChange,
    pdfUrl,
    rects,
    sourceCanvasSize,
    page = 1,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [renderSize, setRenderSize] = useState({ width: 0, height: 0 });

    const sampleDate = sampleSignedDate();
    const sampleSignature = sampleSignatureDataUrl();

    useEffect(() => {
        if (!open) {
            setIsLoading(false);
            setError(null);
            setRenderSize({ width: 0, height: 0 });

            return;
        }

        if (!rects) {
            return;
        }

        let cancelled = false;

        const render = async () => {
            setIsLoading(true);
            setError(null);

            // #region agent log
            fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:render-start',message:'Sample preview render started',data:{open,page,pdfUrl,hasRects:!!rects},timestamp:Date.now(),hypothesisId:'A'})}).catch(()=>{});
            // #endregion

            try {
                const response = await fetch(pdfUrl, {
                    credentials: 'same-origin',
                });

                // #region agent log
                fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:fetch-done',message:'PDF fetch completed',data:{status:response.status,ok:response.ok},timestamp:Date.now(),hypothesisId:'C'})}).catch(()=>{});
                // #endregion

                if (!response.ok) {
                    throw new Error('Failed to load preview PDF.');
                }

                const data = await response.arrayBuffer();
                const pdfjs = await getPdfJs();
                const pdf = await pdfjs.getDocument({ data }).promise;
                const pdfPage = await pdf.getPage(page);
                const container = containerRef.current;
                const canvas = canvasRef.current;

                // #region agent log
                fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:refs-check',message:'Container and canvas refs before render',data:{hasContainer:!!container,hasCanvas:!!canvas,containerWidth:container?.clientWidth??0,isLoadingState:true,cancelled},timestamp:Date.now(),hypothesisId:'A'})}).catch(()=>{});
                // #endregion

                if (!container || !canvas || cancelled) {
                    // #region agent log
                    fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:early-return',message:'Early return - missing refs or cancelled',data:{hasContainer:!!container,hasCanvas:!!canvas,cancelled},timestamp:Date.now(),hypothesisId:'A'})}).catch(()=>{});
                    // #endregion
                    return;
                }

                const baseViewport = pdfPage.getViewport({ scale: 1 });
                const scale = container.clientWidth / baseViewport.width;
                const viewport = pdfPage.getViewport({ scale });
                const context = canvas.getContext('2d');

                if (!context) {
                    throw new Error('Could not initialize PDF canvas.');
                }

                canvas.width = viewport.width;
                canvas.height = viewport.height;

                await pdfPage.render({
                    canvasContext: context,
                    viewport,
                    canvas,
                }).promise;

                if (!cancelled) {
                    setRenderSize({
                        width: viewport.width,
                        height: viewport.height,
                    });
                    setIsLoading(false);
                    // #region agent log
                    fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:render-success',message:'PDF render succeeded',data:{width:viewport.width,height:viewport.height,scale,sourceCanvasSize,rectSignature:rects?.signature},timestamp:Date.now(),hypothesisId:'F',runId:'post-fix'})}).catch(()=>{});
                    // #endregion
                }
            } catch (loadError) {
                if (!cancelled) {
                    // #region agent log
                    fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'esign-placement-sample-preview-dialog.tsx:render-error',message:'PDF render failed',data:{error:loadError instanceof Error?loadError.message:String(loadError)},timestamp:Date.now(),hypothesisId:'D'})}).catch(()=>{});
                    // #endregion
                    setError(
                        loadError instanceof Error
                            ? loadError.message
                            : 'Failed to load sample preview.',
                    );
                    setIsLoading(false);
                }
            }
        };

        void render();

        return () => {
            cancelled = true;
        };
    }, [open, page, pdfUrl, rects]);

    const overlays: OverlaySpec[] = (() => {
        if (!rects || renderSize.width === 0 || renderSize.height === 0) {
            return [];
        }

        const source = sourceCanvasSize ?? renderSize;
        const scaledRects =
            source.width === renderSize.width &&
            source.height === renderSize.height
                ? rects
                : scalePlacementRects(
                      rects,
                      source.width,
                      source.height,
                      renderSize.width,
                      renderSize.height,
                  );

        return [
            {
                key: 'signature_en',
                label: 'Employee draws here (EN)',
                rect: scaledRects.signature,
                kind: 'signature' as const,
            },
            {
                key: 'signature_ar',
                label: 'Stamped on approve (AR)',
                rect: scaledRects.signature_ar,
                kind: 'signature' as const,
            },
            {
                key: 'date_en',
                label: 'Stamped on approve (EN)',
                rect: scaledRects.date,
                kind: 'date' as const,
            },
            {
                key: 'date_ar',
                label: 'Stamped on approve (AR)',
                rect: scaledRects.date_ar,
                kind: 'date' as const,
            },
        ];
    })();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Sample data preview</DialogTitle>
                    <DialogDescription>
                        Example of how the employee signature overlay and
                        stamped dates will appear using the current box
                        positions. Sample date: {sampleDate}.
                    </DialogDescription>
                </DialogHeader>

                {!rects ? (
                    <p className="text-sm text-muted-foreground">
                        Placement boxes are not ready yet. Wait for the editor
                        to load, then try again.
                    </p>
                ) : (
                    <div className="space-y-4">
                        <div
                            ref={containerRef}
                            className="relative overflow-hidden rounded-xl border border-border/80 bg-muted/20"
                        >
                            {isLoading ? (
                                <div className="absolute inset-0 z-10 flex min-h-80 items-center justify-center bg-muted/20">
                                    <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                                </div>
                            ) : null}

                            {error ? (
                                <div className="flex min-h-80 items-center justify-center p-6 text-sm text-destructive">
                                    {error}
                                </div>
                            ) : null}

                            <div
                                className={
                                    error
                                        ? 'hidden'
                                        : 'relative mx-auto w-full'
                                }
                                style={
                                    renderSize.width > 0
                                        ? { maxWidth: renderSize.width }
                                        : undefined
                                }
                            >
                                <canvas
                                    ref={canvasRef}
                                    className={
                                        isLoading || error
                                            ? 'invisible block h-auto w-full'
                                            : 'block h-auto w-full'
                                    }
                                />

                                {!isLoading && !error && renderSize.width > 0
                                    ? overlays.map((overlay) => (
                                          <div
                                              key={overlay.key}
                                              className="pointer-events-none absolute overflow-hidden rounded border border-dashed border-primary/50 bg-primary/5"
                                              style={{
                                                  left: `${(overlay.rect.left / renderSize.width) * 100}%`,
                                                  top: `${(overlay.rect.top / renderSize.height) * 100}%`,
                                                  width: `${(overlay.rect.width / renderSize.width) * 100}%`,
                                                  height: `${(overlay.rect.height / renderSize.height) * 100}%`,
                                              }}
                                              title={overlay.label}
                                          >
                                              {overlay.kind === 'signature' ? (
                                                  <img
                                                      src={sampleSignature}
                                                      alt="Sample signature"
                                                      className="h-full w-full object-contain p-1"
                                                  />
                                              ) : (
                                                  <div className="flex h-full items-center px-2 text-sm font-semibold text-foreground">
                                                      {sampleDate}
                                                  </div>
                                              )}
                                          </div>
                                      ))
                                    : null}
                            </div>
                        </div>

                        <ul className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                            <li>
                                <span className="font-semibold text-foreground">
                                    EN signature box
                                </span>{' '}
                                — employee draws here on the public e-sign page.
                            </li>
                            <li>
                                <span className="font-semibold text-foreground">
                                    AR signature
                                </span>{' '}
                                — same image stamped on approve.
                            </li>
                            <li>
                                <span className="font-semibold text-foreground">
                                    EN / AR date
                                </span>{' '}
                                — today&apos;s date stamped on approve (
                                {sampleDate}).
                            </li>
                        </ul>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
