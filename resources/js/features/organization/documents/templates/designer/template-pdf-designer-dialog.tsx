import { router } from '@inertiajs/react';
import { Canvas, FabricImage, FabricText, Rect } from 'fabric';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    ChevronLeft,
    ChevronRight,
    Eye,
    EyeOff,
    Loader2,
    Plus,
    Save,
    Search,
    Send,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getPdfJs } from '@/lib/pdfjs';
import {
    publish as publishVersion,
    sourcePdf,
} from '@/routes/organization/documents/templates/versions';
import { save as savePlacements } from '@/routes/organization/documents/templates/versions/placements';
import { normalizedToPixel, pixelToNormalized } from '../lib/coordinates';
import type {
    CustomTemplate,
    MergeField,
    PdfPlacementItem,
    PlacementConfig,
    TemplateVersionSummary,
} from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    version: TemplateVersionSummary | null;
    initialConfig: PlacementConfig | null;
    mergeFields: MergeField[];
    onSaved?: () => void;
};

const DEFAULT_PLACEMENT_WIDTH = 160;
const DEFAULT_PLACEMENT_HEIGHT = 26;

export function TemplatePdfDesignerDialog({
    open,
    onOpenChange,
    template,
    version,
    initialConfig,
    mergeFields,
    onSaved,
}: Props) {
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [placements, setPlacements] = useState<PdfPlacementItem[]>([]);
    const [selectedPlacementId, setSelectedPlacementId] = useState<
        string | null
    >(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSamplePreview, setIsSamplePreview] = useState(false);
    const [isLoadingPdf, setIsLoadingPdf] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isPublishing, setIsPublishing] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
    const [isDiscardConfirmOpen, setIsDiscardConfirmOpen] = useState(false);

    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const fabricCanvasRef = useRef<Canvas | null>(null);
    const labelRefs = useRef<Map<string, FabricText>>(new Map());
    const placementsRef = useRef(placements);
    useEffect(() => {
        placementsRef.current = placements;
    }, [placements]);

    const canvasSizeRef = useRef(canvasSize);
    useEffect(() => {
        canvasSizeRef.current = canvasSize;
    }, [canvasSize]);

    const isSamplePreviewRef = useRef(isSamplePreview);
    useEffect(() => {
        isSamplePreviewRef.current = isSamplePreview;
    }, [isSamplePreview]);

    const pdfDocRef = useRef<any>(null);

    // Map of merge fields for quick lookup
    const mergeFieldsMap = useMemo(() => {
        const map = new Map<string, MergeField>();
        mergeFields.forEach((f) => map.set(f.key, f));

        return map;
    }, [mergeFields]);

    // Group merge fields by category
    const categories = useMemo(() => {
        const groups: Record<string, MergeField[]> = {};
        const query = searchQuery.trim().toLowerCase();

        mergeFields.forEach((field) => {
            if (
                query &&
                !field.label.toLowerCase().includes(query) &&
                !field.key.toLowerCase().includes(query)
            ) {
                return;
            }

            if (!groups[field.category]) {
                groups[field.category] = [];
            }

            groups[field.category].push(field);
        });

        return groups;
    }, [mergeFields, searchQuery]);

    // Initialize placements when dialog opens or version changes
    useEffect(() => {
        if (open && version) {
            const rawPlacements = initialConfig?.placements || [];
            setPlacements(rawPlacements);
            placementsRef.current = rawPlacements;
            setCurrentPage(1);
            setTotalPages(version.source_pdf_page_count || 1);
            setSelectedPlacementId(null);
            setIsSamplePreview(false);
            setErrorMessage(null);
            setHasUnsavedChanges(false);
            pdfDocRef.current = null;
        }
    }, [open, version, initialConfig]);

    // Cleanup Fabric canvas on unmount or close
    useEffect(() => {
        return () => {
            if (fabricCanvasRef.current) {
                fabricCanvasRef.current.dispose();
                fabricCanvasRef.current = null;
            }
        };
    }, []);

    // Sync labels on top of placement rects
    const syncLabels = useCallback((canvas: Canvas) => {
        labelRefs.current.forEach((label, id) => {
            const rect = canvas
                .getObjects()
                .find((obj) => (obj.get('data') as { id?: string })?.id === id);

            if (rect) {
                const bounds = rect.getBoundingRect();
                const placement = placementsRef.current.find(
                    (p) => p.id === id,
                );
                const align = placement?.text_align || 'left';
                let labelLeft = bounds.left + 6;
                let originX: 'left' | 'center' | 'right' = 'left';

                if (align === 'center') {
                    labelLeft = bounds.left + bounds.width / 2;
                    originX = 'center';
                } else if (align === 'right') {
                    labelLeft = bounds.left + bounds.width - 6;
                    originX = 'right';
                }

                label.set({
                    left: labelLeft,
                    top: bounds.top + 4,
                    originX,
                });
            }
        });
        canvas.requestRenderAll();
    }, []);

    // Sync placement objects onto Fabric canvas for a specific page without reloading the PDF
    const syncPlacementObjects = useCallback(
        (
            canvas: Canvas,
            items: PdfPlacementItem[],
            page: number,
            preview: boolean,
            width: number,
            height: number,
        ) => {
            const existing = canvas
                .getObjects()
                .filter(
                    (obj) =>
                        Boolean((obj.get('data') as { id?: string })?.id) ||
                        Boolean(
                            (obj.get('data') as { parentId?: string })
                                ?.parentId,
                        ),
                );
            existing.forEach((obj) => canvas.remove(obj));
            labelRefs.current.clear();

            const pagePlacements = items.filter((p) => p.page === page);

            pagePlacements.forEach((item) => {
                const pixel = normalizedToPixel(
                    {
                        x: item.x,
                        y: item.y,
                        width: item.width,
                        height: item.height,
                    },
                    width,
                    height,
                );

                const fieldMeta = mergeFieldsMap.get(item.field);
                const displayText = preview
                    ? fieldMeta?.sample || item.field
                    : fieldMeta?.label || item.field;

                const rect = new Rect({
                    left: pixel.left,
                    top: pixel.top,
                    width: pixel.width,
                    height: pixel.height,
                    fill: preview
                        ? 'rgba(16, 185, 129, 0.2)'
                        : 'rgba(59, 130, 246, 0.25)',
                    stroke: preview ? '#059669' : '#2563eb',
                    strokeWidth: 1.5,
                    cornerColor: '#2563eb',
                    cornerStyle: 'circle',
                    transparentCorners: false,
                    hasRotatingPoint: false,
                    lockRotation: true,
                    selectable: !preview,
                    evented: !preview,
                });
                rect.set('data', { id: item.id });

                const align = item.text_align || 'left';
                let labelLeft = pixel.left + 6;
                let originX: 'left' | 'center' | 'right' = 'left';

                if (align === 'center') {
                    labelLeft = pixel.left + pixel.width / 2;
                    originX = 'center';
                } else if (align === 'right') {
                    labelLeft = pixel.left + pixel.width - 6;
                    originX = 'right';
                }

                const label = new FabricText(displayText, {
                    left: labelLeft,
                    top: pixel.top + 4,
                    fontSize: item.font_size || 12,
                    fontWeight: item.font_weight || 'normal',
                    fill: preview ? '#065f46' : '#1e3a8a',
                    selectable: false,
                    evented: false,
                    originX,
                });
                label.set('data', { parentId: item.id });

                canvas.add(rect);
                canvas.add(label);
                labelRefs.current.set(item.id, label);
            });

            canvas.requestRenderAll();
        },
        [mergeFieldsMap],
    );

    // Attach drag, scale, and selection events to canvas once
    const attachCanvasEvents = useCallback(
        (canvas: Canvas) => {
            canvas.on('object:moving', (e) => {
                const target = e.target;

                if (!target) {
                    return;
                }

                syncLabels(canvas);
                const id = (target.get('data') as { id?: string })?.id;

                if (!id) {
                    return;
                }

                const bounds = target.getBoundingRect();
                const dims = canvasSizeRef.current;

                if (dims.width <= 0 || dims.height <= 0) {
                    return;
                }

                const norm = pixelToNormalized(
                    {
                        left: bounds.left,
                        top: bounds.top,
                        width: bounds.width,
                        height: bounds.height,
                    },
                    dims.width,
                    dims.height,
                );

                setHasUnsavedChanges(true);
                setPlacements((prev) => {
                    const updated = prev.map((p) =>
                        p.id === id ? { ...p, x: norm.x, y: norm.y } : p,
                    );
                    placementsRef.current = updated;

                    return updated;
                });
            });

            canvas.on('object:scaling', (e) => {
                const target = e.target;

                if (!target) {
                    return;
                }

                syncLabels(canvas);
                const id = (target.get('data') as { id?: string })?.id;

                if (!id) {
                    return;
                }

                const bounds = target.getBoundingRect();
                const dims = canvasSizeRef.current;

                if (dims.width <= 0 || dims.height <= 0) {
                    return;
                }

                const norm = pixelToNormalized(
                    {
                        left: bounds.left,
                        top: bounds.top,
                        width: bounds.width,
                        height: bounds.height,
                    },
                    dims.width,
                    dims.height,
                );

                setHasUnsavedChanges(true);
                setPlacements((prev) => {
                    const updated = prev.map((p) =>
                        p.id === id
                            ? {
                                  ...p,
                                  x: norm.x,
                                  y: norm.y,
                                  width: norm.width,
                                  height: norm.height,
                              }
                            : p,
                    );
                    placementsRef.current = updated;

                    return updated;
                });
            });

            canvas.on('selection:created', (e) => {
                const target = e.selected?.[0];
                const id = (target?.get('data') as { id?: string })?.id;
                setSelectedPlacementId(id || null);
            });

            canvas.on('selection:updated', (e) => {
                const target = e.selected?.[0];
                const id = (target?.get('data') as { id?: string })?.id;
                setSelectedPlacementId(id || null);
            });

            canvas.on('selection:cleared', () => {
                setSelectedPlacementId(null);
            });
        },
        [syncLabels],
    );

    // Render PDF page background image and initialize placement objects
    useEffect(() => {
        if (!open || !template || !version) {
            return;
        }

        let cancelled = false;

        const loadAndRenderPdfPage = async () => {
            setIsLoadingPdf(true);
            setErrorMessage(null);

            try {
                let pdf = pdfDocRef.current;

                if (!pdf) {
                    const pdfUrl = sourcePdf.url({
                        template: template.id,
                        version: version.id,
                    });
                    const response = await fetch(pdfUrl, {
                        credentials: 'same-origin',
                    });

                    if (!response.ok) {
                        throw new Error(
                            'Failed to stream private template PDF.',
                        );
                    }

                    const data = await response.arrayBuffer();
                    const pdfjs = await getPdfJs();
                    pdf = await pdfjs.getDocument({ data }).promise;

                    if (cancelled) {
                        return;
                    }

                    pdfDocRef.current = pdf;
                    setTotalPages(pdf.numPages);
                }

                const pageNumber = Math.min(
                    Math.max(1, currentPage),
                    pdf.numPages,
                );
                const pdfPage = await pdf.getPage(pageNumber);

                if (cancelled) {
                    return;
                }

                const unscaledViewport = pdfPage.getViewport({ scale: 1 });
                const targetWidth = Math.min(
                    900,
                    Math.max(600, window.innerWidth * 0.55),
                );
                const scale = targetWidth / unscaledViewport.width;
                const viewport = pdfPage.getViewport({ scale });

                const offscreen = document.createElement('canvas');
                const context = offscreen.getContext('2d');

                if (!context) {
                    throw new Error(
                        'Could not get 2d context for PDF rendering.',
                    );
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
                setCanvasSize({
                    width: viewport.width,
                    height: viewport.height,
                });

                // Initialize or resize Fabric canvas
                let canvas = fabricCanvasRef.current;

                if (!canvas && canvasRef.current) {
                    canvas = new Canvas(canvasRef.current, {
                        width: viewport.width,
                        height: viewport.height,
                        selection: false,
                    });
                    fabricCanvasRef.current = canvas;
                    attachCanvasEvents(canvas);
                } else if (canvas) {
                    canvas.setDimensions({
                        width: viewport.width,
                        height: viewport.height,
                    });
                }

                if (!canvas) {
                    return;
                }

                const bgImage = await FabricImage.fromURL(backgroundUrl);
                canvas.backgroundImage = bgImage;

                // Sync placement objects for this page immediately
                syncPlacementObjects(
                    canvas,
                    placementsRef.current,
                    pageNumber,
                    isSamplePreviewRef.current,
                    viewport.width,
                    viewport.height,
                );

                setIsLoadingPdf(false);
            } catch (err: any) {
                if (!cancelled) {
                    setErrorMessage(
                        err.message || 'Failed to render PDF page.',
                    );
                    setIsLoadingPdf(false);
                }
            }
        };

        loadAndRenderPdfPage();

        return () => {
            cancelled = true;
        };
    }, [
        open,
        template,
        version,
        currentPage,
        attachCanvasEvents,
        syncPlacementObjects,
    ]);

    // Fast sync for sample preview toggle without reloading the PDF
    useEffect(() => {
        const canvas = fabricCanvasRef.current;

        if (
            canvas &&
            canvasSize.width > 0 &&
            canvasSize.height > 0 &&
            !isLoadingPdf
        ) {
            syncPlacementObjects(
                canvas,
                placementsRef.current,
                currentPage,
                isSamplePreview,
                canvasSize.width,
                canvasSize.height,
            );
        }
    }, [
        isSamplePreview,
        currentPage,
        canvasSize,
        isLoadingPdf,
        syncPlacementObjects,
    ]);

    // Handle adding a merge field placement to current page
    const handleAddFieldPlacement = (fieldKey: string) => {
        if (!canvasSize.width || !canvasSize.height) {
            return;
        }

        const newId = crypto.randomUUID();
        const initialPixel = {
            left: Math.round((canvasSize.width - DEFAULT_PLACEMENT_WIDTH) / 2),
            top: Math.round(canvasSize.height / 3),
            width: DEFAULT_PLACEMENT_WIDTH,
            height: DEFAULT_PLACEMENT_HEIGHT,
        };

        const norm = pixelToNormalized(
            initialPixel,
            canvasSize.width,
            canvasSize.height,
        );

        const newPlacement: PdfPlacementItem = {
            id: newId,
            field: fieldKey,
            page: currentPage,
            x: norm.x,
            y: norm.y,
            width: norm.width,
            height: norm.height,
            font_size: 12,
            font_weight: 'normal',
            text_align: 'left',
        };

        setHasUnsavedChanges(true);
        setPlacements((prev) => {
            const updated = [...prev, newPlacement];
            placementsRef.current = updated;

            return updated;
        });

        // Add to active Fabric canvas
        const canvas = fabricCanvasRef.current;

        if (canvas) {
            const fieldMeta = mergeFieldsMap.get(fieldKey);
            const rect = new Rect({
                left: initialPixel.left,
                top: initialPixel.top,
                width: initialPixel.width,
                height: initialPixel.height,
                fill: 'rgba(59, 130, 246, 0.25)',
                stroke: '#2563eb',
                strokeWidth: 1.5,
                cornerColor: '#2563eb',
                cornerStyle: 'circle',
                transparentCorners: false,
                hasRotatingPoint: false,
                lockRotation: true,
            });
            rect.set('data', { id: newId });

            const label = new FabricText(fieldMeta?.label || fieldKey, {
                left: initialPixel.left + 6,
                top: initialPixel.top + 4,
                fontSize: 12,
                fill: '#1e3a8a',
                selectable: false,
                evented: false,
            });
            label.set('data', { parentId: newId });

            canvas.add(rect);
            canvas.add(label);
            labelRefs.current.set(newId, label);
            canvas.setActiveObject(rect);
            setSelectedPlacementId(newId);
            canvas.requestRenderAll();
        }
    };

    // Remove selected placement
    const handleDeletePlacement = (id: string) => {
        setHasUnsavedChanges(true);
        setPlacements((prev) => {
            const updated = prev.filter((p) => p.id !== id);
            placementsRef.current = updated;

            return updated;
        });
        setSelectedPlacementId(null);

        const canvas = fabricCanvasRef.current;

        if (canvas) {
            const rect = canvas
                .getObjects()
                .find((obj) => (obj.get('data') as { id?: string })?.id === id);
            const label = labelRefs.current.get(id);

            if (rect) {
                canvas.remove(rect);
            }

            if (label) {
                canvas.remove(label);
            }

            labelRefs.current.delete(id);
            canvas.requestRenderAll();
        }
    };

    // Save placements via Inertia PUT mutation
    const handleSavePlacements = (
        onSuccessCallback?: () => void,
    ): Promise<boolean> => {
        if (!template || !version) {
            return Promise.resolve(false);
        }

        setIsSaving(true);
        setErrorMessage(null);

        return new Promise<boolean>((resolve) => {
            router.put(
                savePlacements.url({
                    template: template.id,
                    version: version.id,
                }),
                {
                    placements: placementsRef.current.map((p) => ({
                        id: p.id,
                        field: p.field,
                        page: p.page,
                        x: p.x,
                        y: p.y,
                        width: p.width,
                        height: p.height,
                        font_size: p.font_size || 12,
                        font_weight: p.font_weight || 'normal',
                        text_align: p.text_align || 'left',
                    })),
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setIsSaving(false);
                        setHasUnsavedChanges(false);

                        if (onSaved) {
                            onSaved();
                        }

                        if (onSuccessCallback) {
                            onSuccessCallback();
                        }

                        resolve(true);
                    },
                    onError: (err) => {
                        setIsSaving(false);
                        const msg =
                            (Object.values(err)[0] as string) ||
                            'Failed to save placements.';
                        setErrorMessage(msg);
                        resolve(false);
                    },
                },
            );
        });
    };

    // Publish version with atomic save if dirty
    const handlePublish = async () => {
        if (!template || !version) {
            return;
        }

        setErrorMessage(null);

        if (hasUnsavedChanges) {
            setIsPublishing(true);
            const saved = await handleSavePlacements();

            if (!saved) {
                setIsPublishing(false);

                return;
            }
        }

        setIsPublishing(true);
        router.post(
            publishVersion.url({
                template: template.id,
                version: version.id,
            }),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsPublishing(false);
                    setHasUnsavedChanges(false);
                    onOpenChange(false);
                },
                onError: (err) => {
                    setIsPublishing(false);
                    setErrorMessage(
                        (Object.values(err)[0] as string) ||
                            'Failed to publish version.',
                    );
                },
            },
        );
    };

    const handleSafeOpenChange = (newOpen: boolean) => {
        if (!newOpen && hasUnsavedChanges) {
            setIsDiscardConfirmOpen(true);

            return;
        }

        onOpenChange(newOpen);
    };

    const selectedPlacement = placements.find(
        (p) => p.id === selectedPlacementId,
    );

    return (
        <Dialog open={open} onOpenChange={handleSafeOpenChange}>
            <DialogContent className="flex h-[92vh] w-[1400px] max-w-[96vw] flex-col overflow-hidden p-0 sm:max-w-[96vw]">
                <DialogHeader className="flex shrink-0 flex-row items-center justify-between border-b border-border/80 px-6 py-3">
                    <div className="flex items-center gap-3">
                        <DialogTitle className="text-base font-semibold">
                            Visual Field Placement: {template?.name}
                        </DialogTitle>
                        {version && (
                            <Badge
                                variant="secondary"
                                className="text-xs font-medium"
                            >
                                v{version.version} Draft
                            </Badge>
                        )}
                        {isSamplePreview && (
                            <Badge className="border-emerald-500/30 bg-emerald-500/15 text-xs text-emerald-700">
                                Sample Preview Mode
                            </Badge>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Sample preview toggle */}
                        <Button
                            type="button"
                            variant={isSamplePreview ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setIsSamplePreview(!isSamplePreview)}
                            className={
                                isSamplePreview
                                    ? 'bg-emerald-600 hover:bg-emerald-700'
                                    : ''
                            }
                        >
                            {isSamplePreview ? (
                                <>
                                    <EyeOff className="mr-1.5 size-3.5" />
                                    Exit Preview
                                </>
                            ) : (
                                <>
                                    <Eye className="mr-1.5 size-3.5" />
                                    Sample Preview
                                </>
                            )}
                        </Button>

                        {/* Page navigation */}
                        <div className="flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-1.5 py-0.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={currentPage <= 1 || isLoadingPdf}
                                onClick={() =>
                                    setCurrentPage((p) => Math.max(1, p - 1))
                                }
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <span className="px-1 text-xs font-medium select-none">
                                Page {currentPage} of {totalPages}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={
                                    currentPage >= totalPages || isLoadingPdf
                                }
                                onClick={() =>
                                    setCurrentPage((p) =>
                                        Math.min(totalPages, p + 1),
                                    )
                                }
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>

                        {/* Save button */}
                        <Button
                            type="button"
                            size="sm"
                            variant={hasUnsavedChanges ? 'default' : 'outline'}
                            onClick={() => handleSavePlacements()}
                            disabled={isSaving || isLoadingPdf}
                        >
                            {isSaving ? (
                                <>
                                    <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                                    Saving...
                                </>
                            ) : (
                                <>
                                    <Save className="mr-1.5 size-3.5" />
                                    Save Placements
                                    {hasUnsavedChanges && (
                                        <span className="ml-1.5 size-1.5 rounded-full bg-amber-400" />
                                    )}
                                </>
                            )}
                        </Button>

                        {/* Publish button */}
                        <Button
                            type="button"
                            size="sm"
                            onClick={handlePublish}
                            disabled={isPublishing || isSaving}
                            className="bg-primary hover:bg-primary/90"
                        >
                            {isPublishing ? (
                                <>
                                    <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                                    {hasUnsavedChanges
                                        ? 'Saving & Publishing...'
                                        : 'Publishing...'}
                                </>
                            ) : (
                                <>
                                    <Send className="mr-1.5 size-3.5" />
                                    Publish
                                </>
                            )}
                        </Button>
                    </div>
                </DialogHeader>

                {errorMessage && (
                    <div className="flex shrink-0 items-center justify-between bg-destructive/10 px-6 py-2 text-xs font-medium text-destructive">
                        <span>{errorMessage}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-5"
                            onClick={() => setErrorMessage(null)}
                        >
                            <X className="size-3" />
                        </Button>
                    </div>
                )}

                <div className="flex flex-1 overflow-hidden">
                    {/* Left Panel: Merge Fields Catalog */}
                    <div className="flex w-[300px] shrink-0 flex-col border-r border-border/80 bg-muted/20">
                        <div className="border-b border-border/80 p-3">
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                                <Input
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    placeholder="Search merge fields..."
                                    className="h-8 bg-background pl-8 text-xs"
                                />
                            </div>
                        </div>

                        <div className="flex-1 space-y-4 overflow-y-auto p-3">
                            {Object.entries(categories).map(
                                ([category, fields]) => (
                                    <div key={category} className="space-y-1.5">
                                        <p className="px-1 text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                            {category}
                                        </p>
                                        <div className="space-y-1">
                                            {fields.map((field) => (
                                                <div
                                                    key={field.key}
                                                    className="group flex items-center justify-between rounded-lg border border-border/60 bg-background p-2 transition-colors hover:border-primary/50 hover:bg-muted/30"
                                                >
                                                    <div className="min-w-0 pr-2">
                                                        <p className="truncate text-xs font-medium text-foreground">
                                                            {field.label}
                                                        </p>
                                                        <p className="truncate font-mono text-[10px] text-muted-foreground">
                                                            {field.key}
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-6 shrink-0 text-primary opacity-80 group-hover:opacity-100"
                                                        onClick={() =>
                                                            handleAddFieldPlacement(
                                                                field.key,
                                                            )
                                                        }
                                                        disabled={
                                                            isLoadingPdf ||
                                                            isSamplePreview
                                                        }
                                                        title={`Add ${field.label} to page`}
                                                    >
                                                        <Plus className="size-3.5" />
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>

                    {/* Center Area: PDF Page Canvas & Placement Layer */}
                    <div
                        ref={containerRef}
                        className="flex flex-1 flex-col items-center overflow-y-auto bg-muted/40 p-6"
                    >
                        {/* Floating Selection Toolbar */}
                        {selectedPlacement && (
                            <div className="sticky top-0 z-10 mb-4 flex items-center gap-3 rounded-xl border border-border/80 bg-background/95 px-4 py-2 shadow-sm backdrop-blur">
                                <span className="text-xs font-semibold text-foreground">
                                    {mergeFieldsMap.get(selectedPlacement.field)
                                        ?.label || selectedPlacement.field}
                                </span>

                                <div className="h-4 w-px bg-border" />

                                <div className="flex items-center gap-1.5">
                                    <span className="text-[11px] text-muted-foreground">
                                        Font Size:
                                    </span>
                                    <Select
                                        value={String(
                                            selectedPlacement.font_size || 12,
                                        )}
                                        onValueChange={(val) => {
                                            const size = Number(val);
                                            setHasUnsavedChanges(true);
                                            setPlacements((prev) => {
                                                const updated = prev.map((p) =>
                                                    p.id ===
                                                    selectedPlacement.id
                                                        ? {
                                                              ...p,
                                                              font_size: size,
                                                          }
                                                        : p,
                                                );
                                                placementsRef.current = updated;

                                                return updated;
                                            });
                                            const label = labelRefs.current.get(
                                                selectedPlacement.id,
                                            );

                                            if (label) {
                                                label.set('fontSize', size);
                                                fabricCanvasRef.current?.requestRenderAll();
                                            }
                                        }}
                                    >
                                        <SelectTrigger className="h-7 w-16 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                9, 10, 11, 12, 14, 16, 18, 20,
                                                24,
                                            ].map((s) => (
                                                <SelectItem
                                                    key={s}
                                                    value={String(s)}
                                                >
                                                    {s}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="flex items-center gap-1 border-l border-border pl-2">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant={
                                            selectedPlacement.font_weight ===
                                            'bold'
                                                ? 'default'
                                                : 'ghost'
                                        }
                                        className="size-7"
                                        onClick={() => {
                                            const weight: 'bold' | 'normal' =
                                                selectedPlacement.font_weight ===
                                                'bold'
                                                    ? 'normal'
                                                    : 'bold';
                                            setHasUnsavedChanges(true);
                                            setPlacements((prev) => {
                                                const updated = prev.map((p) =>
                                                    p.id ===
                                                    selectedPlacement.id
                                                        ? {
                                                              ...p,
                                                              font_weight:
                                                                  weight,
                                                          }
                                                        : p,
                                                );
                                                placementsRef.current = updated;

                                                return updated;
                                            });
                                            const label = labelRefs.current.get(
                                                selectedPlacement.id,
                                            );

                                            if (label) {
                                                label.set('fontWeight', weight);
                                                fabricCanvasRef.current?.requestRenderAll();
                                            }
                                        }}
                                    >
                                        <Bold className="size-3.5" />
                                    </Button>
                                </div>

                                <div className="flex items-center gap-0.5 border-l border-border pl-2">
                                    {(['left', 'center', 'right'] as const).map(
                                        (align) => {
                                            const isSelected =
                                                (selectedPlacement.text_align ||
                                                    'left') === align;
                                            const Icon =
                                                align === 'left'
                                                    ? AlignLeft
                                                    : align === 'center'
                                                      ? AlignCenter
                                                      : AlignRight;

                                            return (
                                                <Button
                                                    key={align}
                                                    type="button"
                                                    size="icon"
                                                    variant={
                                                        isSelected
                                                            ? 'default'
                                                            : 'ghost'
                                                    }
                                                    className="size-7"
                                                    title={`Align ${align}`}
                                                    onClick={() => {
                                                        setHasUnsavedChanges(
                                                            true,
                                                        );
                                                        setPlacements(
                                                            (prev) => {
                                                                const updated =
                                                                    prev.map(
                                                                        (p) =>
                                                                            p.id ===
                                                                            selectedPlacement.id
                                                                                ? {
                                                                                      ...p,
                                                                                      text_align:
                                                                                          align,
                                                                                  }
                                                                                : p,
                                                                    );
                                                                placementsRef.current =
                                                                    updated;

                                                                return updated;
                                                            },
                                                        );

                                                        const canvas =
                                                            fabricCanvasRef.current;

                                                        if (canvas) {
                                                            const rect = canvas
                                                                .getObjects()
                                                                .find(
                                                                    (obj) =>
                                                                        (
                                                                            obj.get(
                                                                                'data',
                                                                            ) as {
                                                                                id?: string;
                                                                            }
                                                                        )
                                                                            ?.id ===
                                                                        selectedPlacement.id,
                                                                );
                                                            const label =
                                                                labelRefs.current.get(
                                                                    selectedPlacement.id,
                                                                );

                                                            if (rect && label) {
                                                                const bounds =
                                                                    rect.getBoundingRect();
                                                                let labelLeft =
                                                                    bounds.left +
                                                                    6;
                                                                let originX:
                                                                    | 'left'
                                                                    | 'center'
                                                                    | 'right' =
                                                                    'left';

                                                                if (
                                                                    align ===
                                                                    'center'
                                                                ) {
                                                                    labelLeft =
                                                                        bounds.left +
                                                                        bounds.width /
                                                                            2;
                                                                    originX =
                                                                        'center';
                                                                } else if (
                                                                    align ===
                                                                    'right'
                                                                ) {
                                                                    labelLeft =
                                                                        bounds.left +
                                                                        bounds.width -
                                                                        6;
                                                                    originX =
                                                                        'right';
                                                                }

                                                                label.set({
                                                                    left: labelLeft,
                                                                    originX,
                                                                });
                                                                canvas.requestRenderAll();
                                                            }
                                                        }
                                                    }}
                                                >
                                                    <Icon className="size-3.5" />
                                                </Button>
                                            );
                                        },
                                    )}
                                </div>

                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="ml-2 size-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() =>
                                        handleDeletePlacement(
                                            selectedPlacement.id,
                                        )
                                    }
                                    title="Delete placement"
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </div>
                        )}

                        {/* PDF Page Canvas */}
                        <div className="relative overflow-hidden rounded-lg border border-border bg-white shadow-lg">
                            {isLoadingPdf && (
                                <div className="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/70 backdrop-blur-xs">
                                    <Loader2 className="size-8 animate-spin text-primary" />
                                    <p className="mt-2 text-xs font-medium text-muted-foreground">
                                        Loading page {currentPage}...
                                    </p>
                                </div>
                            )}
                            <canvas ref={canvasRef} />
                        </div>
                    </div>
                </div>
            </DialogContent>

            <AlertDialog
                open={isDiscardConfirmOpen}
                onOpenChange={setIsDiscardConfirmOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Unsaved Placement Changes
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            You have unsaved changes in your document layout.
                            Are you sure you want to discard them?
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            onClick={() => setIsDiscardConfirmOpen(false)}
                        >
                            Keep Editing
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className={buttonVariants({
                                variant: 'destructive',
                            })}
                            onClick={() => {
                                setIsDiscardConfirmOpen(false);
                                setHasUnsavedChanges(false);
                                onOpenChange(false);
                            }}
                        >
                            Discard Changes
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Dialog>
    );
}
