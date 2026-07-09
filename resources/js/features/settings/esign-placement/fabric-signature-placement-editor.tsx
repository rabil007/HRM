import { Canvas, FabricImage, FabricText, Rect } from 'fabric';
import { Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    editorRectsFromConfig,
    type EditorRect,
    type SignaturePlacementConfig,
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import { getPdfJs } from '@/lib/pdfjs';

type PlacementRects = {
    signature: EditorRect;
    date: EditorRect;
};

type Props = {
    pdfUrl: string;
    placement: SignaturePlacementConfig;
    page?: number;
    canEdit: boolean;
    onSave: (payload: {
        page: number;
        canvas_width: number;
        canvas_height: number;
        signature: EditorRect;
        date: EditorRect;
    }) => Promise<void>;
    onReset: () => Promise<void>;
    isSaving: boolean;
    isResetting: boolean;
};

const SIGNATURE_ID = 'signature_en';
const DATE_ID = 'date_en';

function createPlacementRect(
    id: string,
    rect: EditorRect,
    fill: string,
    canEdit: boolean,
): Rect {
    const placementRect = new Rect({
        left: rect.left,
        top: rect.top,
        width: rect.width,
        height: rect.height,
        fill,
        stroke: fill.replace('0.35', '1'),
        strokeWidth: 2,
        cornerColor: fill.replace('0.35', '1'),
        cornerStyle: 'circle',
        transparentCorners: false,
        hasRotatingPoint: false,
        lockRotation: true,
        selectable: canEdit,
        evented: canEdit,
    });

    placementRect.set('data', { id });

    return placementRect;
}

export function FabricSignaturePlacementEditor({
    pdfUrl,
    placement,
    page = 1,
    canEdit,
    onSave,
    onReset,
    isSaving,
    isResetting,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const canvasElementRef = useRef<HTMLCanvasElement>(null);
    const fabricCanvasRef = useRef<Canvas | null>(null);
    const labelRefs = useRef<FabricText[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });
    const [rects, setRects] = useState<PlacementRects | null>(null);

    const syncLabels = useCallback((canvas: Canvas) => {
        labelRefs.current.forEach((label) => {
            const parentId = (label.get('data') as { parentId?: string })
                ?.parentId;
            const object = canvas
                .getObjects()
                .find(
                    (item) =>
                        (item.get('data') as { id?: string } | undefined)
                            ?.id === parentId,
                );

            if (!object) {
                return;
            }

            const bounds = object.getBoundingRect();
            label.set({
                left: bounds.left + 6,
                top: bounds.top + 6,
            });
        });

        canvas.requestRenderAll();
    }, []);

    useEffect(() => {
        let cancelled = false;

        const loadPdf = async () => {
            setIsLoading(true);
            setError(null);

            // #region agent log
            fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:loadPdf:start',message:'loadPdf started',data:{pdfUrl,page},timestamp:Date.now(),hypothesisId:'H1'})}).catch(()=>{});
            // #endregion

            try {
                const response = await fetch(pdfUrl, {
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Failed to load preview PDF.');
                }

                const data = await response.arrayBuffer();
                const pdfjs = await getPdfJs();
                const pdf = await pdfjs.getDocument({ data }).promise;
                const pdfPage = await pdf.getPage(page);
                const container = containerRef.current;

                if (!container || cancelled) {
                    return;
                }

                const baseViewport = pdfPage.getViewport({ scale: 1 });
                const scale = container.clientWidth / baseViewport.width;
                const viewport = pdfPage.getViewport({ scale });
                const offscreen = document.createElement('canvas');
                const context = offscreen.getContext('2d');

                if (!context) {
                    throw new Error('Could not initialize PDF canvas.');
                }

                offscreen.width = viewport.width;
                offscreen.height = viewport.height;

                await pdfPage.render({
                    canvasContext: context,
                    viewport,
                    canvas: offscreen,
                }).promise;

                if (cancelled) {
                    return;
                }

                const backgroundUrl = offscreen.toDataURL('image/png');
                const nextRects = editorRectsFromConfig(
                    placement,
                    viewport.width,
                    viewport.height,
                );

                setCanvasSize({
                    width: viewport.width,
                    height: viewport.height,
                });
                setRects(nextRects);
                setIsLoading(false);

                // #region agent log
                fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:loadPdf:pdfRendered',message:'PDF rendered to offscreen canvas',data:{viewportWidth:viewport.width,viewportHeight:viewport.height},timestamp:Date.now(),hypothesisId:'H3'})}).catch(()=>{});
                // #endregion

                requestAnimationFrame(() => {
                    if (cancelled || !canvasElementRef.current) {
                        return;
                    }

                    fabricCanvasRef.current?.dispose();

                    const canvas = new Canvas(canvasElementRef.current, {
                        width: viewport.width,
                        height: viewport.height,
                        selection: canEdit,
                    });

                    fabricCanvasRef.current = canvas;
                    labelRefs.current = [];

                    FabricImage.fromURL(backgroundUrl)
                        .then((image) => {
                        if (cancelled) {
                            return;
                        }

                        // #region agent log
                        fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:fabricImage:loaded',message:'FabricImage.fromURL resolved',data:{imageType:image?.constructor?.name},timestamp:Date.now(),hypothesisId:'H2'})}).catch(()=>{});
                        // #endregion

                        image.set({
                            selectable: false,
                            evented: false,
                            originX: 'left',
                            originY: 'top',
                        });

                        image.scaleToWidth(viewport.width);
                        canvas.backgroundImage = image;

                        const signatureRect = createPlacementRect(
                            SIGNATURE_ID,
                            nextRects.signature,
                            'rgba(59, 130, 246, 0.35)',
                            canEdit,
                        );
                        const dateRect = createPlacementRect(
                            DATE_ID,
                            nextRects.date,
                            'rgba(16, 185, 129, 0.35)',
                            canEdit,
                        );

                        const signatureLabel = new FabricText('Signature (EN)', {
                            left: nextRects.signature.left + 6,
                            top: nextRects.signature.top + 6,
                            fontSize: 12,
                            fontFamily: 'system-ui, sans-serif',
                            fill: '#1d4ed8',
                            selectable: false,
                            evented: false,
                        });
                        signatureLabel.set('data', { parentId: SIGNATURE_ID });

                        const dateLabel = new FabricText('Date (EN)', {
                            left: nextRects.date.left + 6,
                            top: nextRects.date.top + 6,
                            fontSize: 12,
                            fontFamily: 'system-ui, sans-serif',
                            fill: '#047857',
                            selectable: false,
                            evented: false,
                        });
                        dateLabel.set('data', { parentId: DATE_ID });

                        labelRefs.current = [signatureLabel, dateLabel];

                        canvas.add(signatureRect, dateRect, signatureLabel, dateLabel);

                        canvas.on('object:moving', () => syncLabels(canvas));
                        canvas.on('object:scaling', () => syncLabels(canvas));
                        canvas.on('object:modified', () => syncLabels(canvas));

                        canvas.renderAll();

                        // #region agent log
                        fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:fabricCanvas:ready',message:'Fabric canvas initialized with placement rects',data:{objectCount:canvas.getObjects().length},timestamp:Date.now(),hypothesisId:'H1',runId:'post-fix'})}).catch(()=>{});
                        // #endregion
                    })
                        .catch((fabricError: unknown) => {
                            if (cancelled) {
                                return;
                            }

                            const message =
                                fabricError instanceof Error
                                    ? fabricError.message
                                    : 'Failed to initialize placement editor.';

                            // #region agent log
                            fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:fabricImage:error',message:'FabricImage or canvas setup failed',data:{errorMessage:message},timestamp:Date.now(),hypothesisId:'H2'})}).catch(()=>{});
                            // #endregion

                            setError(message);
                            setIsLoading(false);
                        });
                });
            } catch (loadError) {
                if (!cancelled) {
                    const message =
                        loadError instanceof Error
                            ? loadError.message
                            : 'Failed to load preview PDF.';

                    // #region agent log
                    fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'9313b6'},body:JSON.stringify({sessionId:'9313b6',location:'fabric-signature-placement-editor.tsx:loadPdf:error',message:'loadPdf catch',data:{errorMessage:message},timestamp:Date.now(),hypothesisId:'H3'})}).catch(()=>{});
                    // #endregion

                    setError(message);
                    setIsLoading(false);
                }
            }
        };

        void loadPdf();

        return () => {
            cancelled = true;
            fabricCanvasRef.current?.dispose();
            fabricCanvasRef.current = null;
        };
    }, [canEdit, page, pdfUrl, placement, syncLabels]);

    const readRectsFromCanvas = (): PlacementRects | null => {
        const canvas = fabricCanvasRef.current;

        if (!canvas) {
            return null;
        }

        const signatureObject = canvas
            .getObjects()
            .find(
                (item) =>
                    (item.get('data') as { id?: string } | undefined)?.id ===
                    SIGNATURE_ID,
            );
        const dateObject = canvas
            .getObjects()
            .find(
                (item) =>
                    (item.get('data') as { id?: string } | undefined)?.id ===
                    DATE_ID,
            );

        if (!signatureObject || !dateObject) {
            return null;
        }

        const signatureBounds = signatureObject.getBoundingRect();
        const dateBounds = dateObject.getBoundingRect();

        return {
            signature: {
                left: signatureBounds.left,
                top: signatureBounds.top,
                width: signatureBounds.width,
                height: signatureBounds.height,
            },
            date: {
                left: dateBounds.left,
                top: dateBounds.top,
                width: dateBounds.width,
                height: dateBounds.height,
            },
        };
    };

    const handleSave = async () => {
        const currentRects = readRectsFromCanvas();

        if (!currentRects || canvasSize.width === 0 || canvasSize.height === 0) {
            return;
        }

        await onSave({
            page,
            canvas_width: canvasSize.width,
            canvas_height: canvasSize.height,
            signature: currentRects.signature,
            date: currentRects.date,
        });
    };

    return (
        <div className="space-y-4">
            <div
                ref={containerRef}
                className="relative overflow-hidden rounded-xl border border-border/80 bg-muted/20"
            >
                {isLoading ? (
                    <div className="flex min-h-80 items-center justify-center">
                        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                    </div>
                ) : null}

                {error ? (
                    <div className="flex min-h-80 items-center justify-center p-6 text-sm text-destructive">
                        {error}
                    </div>
                ) : null}

                <canvas
                    ref={canvasElementRef}
                    className={isLoading || error ? 'hidden' : 'mx-auto block max-w-full'}
                />
            </div>

            <p className="text-xs text-muted-foreground">
                Drag the English signature and date boxes onto the matching lines.
                Arabic positions mirror automatically when employees sign and when
                PDFs are stamped.
            </p>

            {canEdit ? (
                <div className="flex flex-wrap gap-3">
                    <Button
                        type="button"
                        onClick={() => void handleSave()}
                        disabled={isSaving || isResetting || isLoading || !!error}
                    >
                        {isSaving ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Saving...
                            </>
                        ) : (
                            'Save placement'
                        )}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => void onReset()}
                        disabled={isSaving || isResetting || isLoading || !!error}
                    >
                        {isResetting ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Resetting...
                            </>
                        ) : (
                            'Reset to defaults'
                        )}
                    </Button>
                </div>
            ) : null}

            {rects ? (
                <p className="sr-only">
                    Placement editor loaded for canvas {canvasSize.width} by{' '}
                    {canvasSize.height}.
                </p>
            ) : null}
        </div>
    );
}
