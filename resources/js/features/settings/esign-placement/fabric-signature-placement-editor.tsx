import { Canvas, FabricImage, FabricText, Rect } from 'fabric';
import { Eye, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    editorRectsFromConfig
    
    
    
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import type {EditorPlacementRects, EditorRect, SignaturePlacementConfig} from '@/features/settings/esign-placement/esign-placement-coordinates';
import { EsignPlacementSamplePreviewDialog } from '@/features/settings/esign-placement/esign-placement-sample-preview-dialog';
import { getPdfJs } from '@/lib/pdfjs';

type Props = {
    pdfUrl: string;
    samplePdfUrl: string;
    placement: SignaturePlacementConfig;
    page?: number;
    canEdit: boolean;
    onSave: (payload: {
        page: number;
        canvas_width: number;
        canvas_height: number;
        signature: EditorRect;
        date: EditorRect;
        signature_ar: EditorRect;
        date_ar: EditorRect;
    }) => Promise<void>;
    onReset: () => Promise<void>;
    isSaving: boolean;
    isResetting: boolean;
};

const SIGNATURE_EN_ID = 'signature_en';
const DATE_EN_ID = 'date_en';
const SIGNATURE_AR_ID = 'signature_ar';
const DATE_AR_ID = 'date_ar';

const PLACEMENT_FIELDS = [
    {
        id: SIGNATURE_EN_ID,
        label: 'Signature (EN)',
        color: '#1d4ed8',
        fill: 'rgba(59, 130, 246, 0.35)',
        rectKey: 'signature' as const,
    },
    {
        id: DATE_EN_ID,
        label: 'Date (EN)',
        color: '#047857',
        fill: 'rgba(16, 185, 129, 0.35)',
        rectKey: 'date' as const,
    },
    {
        id: SIGNATURE_AR_ID,
        label: 'Signature (AR)',
        color: '#c2410c',
        fill: 'rgba(249, 115, 22, 0.35)',
        rectKey: 'signature_ar' as const,
    },
    {
        id: DATE_AR_ID,
        label: 'Date (AR)',
        color: '#7c3aed',
        fill: 'rgba(139, 92, 246, 0.35)',
        rectKey: 'date_ar' as const,
    },
];

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
    samplePdfUrl,
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
    const [rects, setRects] = useState<EditorPlacementRects | null>(null);
    const [samplePreviewOpen, setSamplePreviewOpen] = useState(false);
    const [samplePreviewRects, setSamplePreviewRects] =
        useState<EditorPlacementRects | null>(null);

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

                            image.set({
                                selectable: false,
                                evented: false,
                                originX: 'left',
                                originY: 'top',
                            });

                            image.scaleToWidth(viewport.width);
                            canvas.backgroundImage = image;

                            const objects: Array<Rect | FabricText> = [];

                            for (const field of PLACEMENT_FIELDS) {
                                const rect = nextRects[field.rectKey];
                                const placementRect = createPlacementRect(
                                    field.id,
                                    rect,
                                    field.fill,
                                    canEdit,
                                );
                                const label = new FabricText(field.label, {
                                    left: rect.left + 6,
                                    top: rect.top + 6,
                                    fontSize: 12,
                                    fontFamily: 'Arial, Helvetica, sans-serif',
                                    fill: field.color,
                                    selectable: false,
                                    evented: false,
                                });
                                label.set('data', { parentId: field.id });
                                labelRefs.current.push(label);
                                objects.push(placementRect, label);
                            }

                            canvas.add(...objects);

                            canvas.on('object:moving', () =>
                                syncLabels(canvas),
                            );
                            canvas.on('object:scaling', () =>
                                syncLabels(canvas),
                            );
                            canvas.on('object:modified', () =>
                                syncLabels(canvas),
                            );

                            canvas.renderAll();
                        })
                        .catch((fabricError: unknown) => {
                            if (cancelled) {
                                return;
                            }

                            setError(
                                fabricError instanceof Error
                                    ? fabricError.message
                                    : 'Failed to initialize placement editor.',
                            );
                            setIsLoading(false);
                        });
                });
            } catch (loadError) {
                if (!cancelled) {
                    setError(
                        loadError instanceof Error
                            ? loadError.message
                            : 'Failed to load preview PDF.',
                    );
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

    const readRectById = (id: string): EditorRect | null => {
        const canvas = fabricCanvasRef.current;

        if (!canvas) {
            return null;
        }

        const object = canvas
            .getObjects()
            .find(
                (item) =>
                    (item.get('data') as { id?: string } | undefined)?.id ===
                    id,
            );

        if (!object) {
            return null;
        }

        const bounds = object.getBoundingRect();

        return {
            left: bounds.left,
            top: bounds.top,
            width: bounds.width,
            height: bounds.height,
        };
    };

    const readRectsFromCanvas = (): EditorPlacementRects | null => {
        const signature = readRectById(SIGNATURE_EN_ID);
        const date = readRectById(DATE_EN_ID);
        const signatureAr = readRectById(SIGNATURE_AR_ID);
        const dateAr = readRectById(DATE_AR_ID);

        if (!signature || !date || !signatureAr || !dateAr) {
            return null;
        }

        return {
            signature,
            date,
            signature_ar: signatureAr,
            date_ar: dateAr,
        };
    };

    const handleOpenSamplePreview = () => {
        const currentRects = readRectsFromCanvas() ?? rects;

        if (!currentRects) {
            return;
        }

        setSamplePreviewRects(currentRects);
        setSamplePreviewOpen(true);
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
            signature_ar: currentRects.signature_ar,
            date_ar: currentRects.date_ar,
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
                Drag all four boxes onto the matching English and Arabic
                signature and date lines. Dashed guides in the preview PDF show
                the template placeholder areas.
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
                        onClick={handleOpenSamplePreview}
                        disabled={isLoading || !!error || !rects}
                    >
                        <Eye className="mr-2 h-4 w-4" />
                        Preview sample data
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
            ) : (
                <div className="flex flex-wrap gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={handleOpenSamplePreview}
                        disabled={isLoading || !!error || !rects}
                    >
                        <Eye className="mr-2 h-4 w-4" />
                        Preview sample data
                    </Button>
                </div>
            )}

            <EsignPlacementSamplePreviewDialog
                open={samplePreviewOpen}
                onOpenChange={setSamplePreviewOpen}
                pdfUrl={samplePdfUrl}
                rects={samplePreviewRects}
                sourceCanvasSize={
                    canvasSize.width > 0 ? canvasSize : undefined
                }
                page={page}
            />

            {rects ? (
                <p className="sr-only">
                    Placement editor loaded for canvas {canvasSize.width} by{' '}
                    {canvasSize.height}.
                </p>
            ) : null}
        </div>
    );
}
