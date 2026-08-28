import { router } from '@inertiajs/react';
import { Canvas, FabricImage, FabricText, Rect } from 'fabric';
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    PenLine,
    Save,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getPdfJs } from '@/lib/pdfjs';
import { sourcePdf } from '@/routes/organization/documents/templates/versions';
import { save as saveSignaturePlacement } from '@/routes/organization/documents/templates/versions/signature-placement';
import { normalizedToPixel, pixelToNormalized } from '../lib/coordinates';
import type {
    CustomTemplate,
    SignaturePlacementConfig,
    SignaturePlacementItem,
    TemplateVersionSummary,
} from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    version: TemplateVersionSummary | null;
    initialConfig: SignaturePlacementConfig | null;
};

const SUBJECT_SIGNATURE_ID = 'subject_signature';
const COMPANY_SIGNATURE_ID = 'company_signatory_signature';
const DEFAULT_WIDTH = 0.25;
const DEFAULT_HEIGHT = 0.08;
const DEFAULT_X = 0.1;
const DEFAULT_Y = 0.75;

type SignatureRole = SignaturePlacementItem['role'];

function defaultPlacement(
    role: SignatureRole,
    page: number,
): SignaturePlacementItem {
    return {
        id: role === 'subject' ? SUBJECT_SIGNATURE_ID : COMPANY_SIGNATURE_ID,
        type: 'signature',
        role,
        page,
        x: DEFAULT_X,
        y: role === 'subject' ? DEFAULT_Y : 0.62,
        width: DEFAULT_WIDTH,
        height: DEFAULT_HEIGHT,
        required: true,
    };
}

function roleLabel(role: SignatureRole): string {
    return role === 'subject'
        ? 'Employee Signature'
        : 'Company Signatory Signature';
}

function roleColors(role: SignatureRole): {
    fill: string;
    stroke: string;
    text: string;
} {
    return role === 'subject'
        ? {
              fill: 'rgba(37, 99, 235, 0.28)',
              stroke: '#2563eb',
              text: '#1e3a8a',
          }
        : {
              fill: 'rgba(180, 83, 9, 0.28)',
              stroke: '#b45309',
              text: '#78350f',
          };
}

export function TemplateSignaturePlacementDialog({
    open,
    onOpenChange,
    template,
    version,
    initialConfig,
}: Props) {
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [activeRole, setActiveRole] = useState<SignatureRole>('subject');
    const [placement, setPlacement] = useState<SignaturePlacementItem>(
        defaultPlacement('subject', 1),
    );
    const [companyEnabled, setCompanyEnabled] = useState(false);
    const [isLoadingPdf, setIsLoadingPdf] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);

    const containerRef = useRef<HTMLDivElement>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const fabricCanvasRef = useRef<Canvas | null>(null);
    const labelRef = useRef<FabricText | null>(null);
    const placementRef = useRef(placement);
    const placementsByRoleRef = useRef<
        Partial<Record<SignatureRole, SignaturePlacementItem>>
    >({});
    const activeRoleRef = useRef(activeRole);
    const canvasSizeRef = useRef(canvasSize);
    const pdfDocRef = useRef<any>(null);

    useEffect(() => {
        placementRef.current = placement;
    }, [placement]);

    useEffect(() => {
        canvasSizeRef.current = canvasSize;
    }, [canvasSize]);

    useEffect(() => {
        activeRoleRef.current = activeRole;
    }, [activeRole]);

    useEffect(() => {
        if (!open || !version) {
            return;
        }

        const subjectExisting = initialConfig?.placements?.find(
            (item) => item.role === 'subject',
        );
        const companyExisting = initialConfig?.placements?.find(
            (item) => item.role === 'company_signatory',
        );

        const subject = subjectExisting
            ? {
                  id: subjectExisting.id || SUBJECT_SIGNATURE_ID,
                  type: 'signature' as const,
                  role: 'subject' as const,
                  page: subjectExisting.page || 1,
                  x: subjectExisting.x,
                  y: subjectExisting.y,
                  width: subjectExisting.width,
                  height: subjectExisting.height,
                  required: subjectExisting.required ?? true,
              }
            : defaultPlacement('subject', 1);

        placementsByRoleRef.current = { subject };

        if (companyExisting) {
            placementsByRoleRef.current.company_signatory = {
                id: companyExisting.id || COMPANY_SIGNATURE_ID,
                type: 'signature',
                role: 'company_signatory',
                page: companyExisting.page || 1,
                x: companyExisting.x,
                y: companyExisting.y,
                width: companyExisting.width,
                height: companyExisting.height,
                required: companyExisting.required ?? true,
            };
            setCompanyEnabled(true);
        } else {
            setCompanyEnabled(false);
        }

        setActiveRole('subject');
        setPlacement(subject);
        placementRef.current = subject;
        setCurrentPage(subject.page);
        setTotalPages(version.source_pdf_page_count || 1);
        setErrorMessage(null);
        setHasUnsavedChanges(false);
        pdfDocRef.current = null;
    }, [open, version, initialConfig]);

    useEffect(() => {
        return () => {
            if (fabricCanvasRef.current) {
                fabricCanvasRef.current.dispose();
                fabricCanvasRef.current = null;
            }
        };
    }, []);

    const syncLabel = useCallback((canvas: Canvas, role: SignatureRole) => {
        const label = labelRef.current;
        const placementId =
            role === 'subject' ? SUBJECT_SIGNATURE_ID : COMPANY_SIGNATURE_ID;
        const rect = canvas
            .getObjects()
            .find(
                (obj) =>
                    (obj.get('data') as { id?: string } | undefined)?.id ===
                    placementId,
            );

        if (!label || !rect) {
            return;
        }

        const bounds = rect.getBoundingRect();
        label.set({
            left: bounds.left + 6,
            top: bounds.top + 6,
        });
        canvas.requestRenderAll();
    }, []);

    const syncPlacementRect = useCallback(
        (
            canvas: Canvas,
            item: SignaturePlacementItem,
            width: number,
            height: number,
        ) => {
            canvas.getObjects().forEach((obj) => {
                if ((obj.get('data') as { id?: string } | undefined)?.id) {
                    canvas.remove(obj);
                }
            });
            labelRef.current = null;

            if (item.page !== currentPage || width <= 0 || height <= 0) {
                canvas.requestRenderAll();

                return;
            }

            const colors = roleColors(item.role);
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

            const rect = new Rect({
                left: pixel.left,
                top: pixel.top,
                width: pixel.width,
                height: pixel.height,
                fill: colors.fill,
                stroke: colors.stroke,
                strokeWidth: 2,
                cornerColor: colors.stroke,
                cornerStyle: 'circle',
                transparentCorners: false,
                hasRotatingPoint: false,
                lockRotation: true,
                selectable: true,
                evented: true,
            });
            rect.set('data', { id: item.id });

            const label = new FabricText(roleLabel(item.role), {
                left: pixel.left + 6,
                top: pixel.top + 6,
                fontSize: 12,
                fontFamily: 'ui-sans-serif, system-ui, sans-serif',
                fill: colors.text,
                selectable: false,
                evented: false,
            });
            label.set('data', { parentId: item.id });
            labelRef.current = label;

            canvas.add(rect);
            canvas.add(label);
            canvas.setActiveObject(rect);
            canvas.requestRenderAll();
        },
        [currentPage],
    );

    const attachCanvasEvents = useCallback(
        (canvas: Canvas) => {
            const persistFromCanvas = () => {
                const role = activeRoleRef.current;
                const placementId =
                    role === 'subject'
                        ? SUBJECT_SIGNATURE_ID
                        : COMPANY_SIGNATURE_ID;
                const rect = canvas
                    .getObjects()
                    .find(
                        (obj) =>
                            (obj.get('data') as { id?: string } | undefined)
                                ?.id === placementId,
                    );

                if (!rect) {
                    return;
                }

                const size = canvasSizeRef.current;

                if (size.width <= 0 || size.height <= 0) {
                    return;
                }

                const bounds = rect.getBoundingRect();
                const normalized = pixelToNormalized(
                    {
                        left: bounds.left,
                        top: bounds.top,
                        width: bounds.width,
                        height: bounds.height,
                    },
                    size.width,
                    size.height,
                );

                const updated: SignaturePlacementItem = {
                    ...placementRef.current,
                    page: currentPage,
                    x: normalized.x,
                    y: normalized.y,
                    width: normalized.width,
                    height: normalized.height,
                };
                placementRef.current = updated;
                placementsByRoleRef.current[role] = updated;
                setPlacement(updated);
                setHasUnsavedChanges(true);
                syncLabel(canvas, role);
            };

            canvas.off('object:modified');
            canvas.off('object:moving');
            canvas.off('object:scaling');
            canvas.on('object:modified', persistFromCanvas);
            canvas.on('object:moving', () =>
                syncLabel(canvas, activeRoleRef.current),
            );
            canvas.on('object:scaling', () =>
                syncLabel(canvas, activeRoleRef.current),
            );
        },
        [currentPage, syncLabel],
    );

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
                    attachCanvasEvents(canvas);
                }

                if (!canvas) {
                    return;
                }

                const bgImage = await FabricImage.fromURL(backgroundUrl);
                canvas.backgroundImage = bgImage;
                syncPlacementRect(
                    canvas,
                    placementRef.current,
                    viewport.width,
                    viewport.height,
                );
            } catch (error) {
                if (!cancelled) {
                    setErrorMessage(
                        error instanceof Error
                            ? error.message
                            : 'Failed to load template PDF.',
                    );
                }
            } finally {
                if (!cancelled) {
                    setIsLoadingPdf(false);
                }
            }
        };

        void loadAndRenderPdfPage();

        return () => {
            cancelled = true;
        };
    }, [
        open,
        template,
        version,
        currentPage,
        activeRole,
        placement,
        attachCanvasEvents,
        syncPlacementRect,
    ]);

    const handleRoleChange = (role: SignatureRole) => {
        placementsByRoleRef.current[activeRole] = placementRef.current;

        const next =
            placementsByRoleRef.current[role] ??
            defaultPlacement(role, currentPage);

        placementsByRoleRef.current[role] = next;
        activeRoleRef.current = role;
        setActiveRole(role);
        setPlacement(next);
        placementRef.current = next;
        setHasUnsavedChanges(true);
    };

    const toggleCompanyPlacement = (enabled: boolean) => {
        setCompanyEnabled(enabled);

        if (enabled) {
            const next =
                placementsByRoleRef.current.company_signatory ??
                defaultPlacement('company_signatory', currentPage);
            placementsByRoleRef.current.company_signatory = next;
        } else {
            delete placementsByRoleRef.current.company_signatory;

            if (activeRole === 'company_signatory') {
                handleRoleChange('subject');
            }
        }

        setHasUnsavedChanges(true);
    };

    const handlePageChange = (nextPage: number) => {
        if (nextPage < 1 || nextPage > totalPages) {
            return;
        }

        // Keep box on the selected page when navigating.
        const updated = {
            ...placementRef.current,
            page: nextPage,
        };
        placementRef.current = updated;
        setPlacement(updated);
        setHasUnsavedChanges(true);
        setCurrentPage(nextPage);
    };

    const handleSave = (): Promise<boolean> => {
        if (!template || !version) {
            return Promise.resolve(false);
        }

        setIsSaving(true);
        setErrorMessage(null);

        const subject =
            placementsByRoleRef.current.subject ?? placementRef.current;
        const placements: SignaturePlacementItem[] = [
            {
                ...subject,
                id: SUBJECT_SIGNATURE_ID,
                type: 'signature',
                role: 'subject',
                required: true,
            },
        ];

        if (companyEnabled && placementsByRoleRef.current.company_signatory) {
            placements.push({
                ...placementsByRoleRef.current.company_signatory,
                id: COMPANY_SIGNATURE_ID,
                type: 'signature',
                role: 'company_signatory',
                required: true,
            });
        }

        const payload: SignaturePlacementConfig = {
            schema_version: 1,
            placements,
        };

        return new Promise<boolean>((resolve) => {
            router.put(
                saveSignaturePlacement.url({
                    template: template.id,
                    version: version.id,
                }),
                payload,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setIsSaving(false);
                        setHasUnsavedChanges(false);
                        resolve(true);
                    },
                    onError: (err) => {
                        setIsSaving(false);
                        const msg =
                            (Object.values(err)[0] as string) ||
                            'Failed to save signature placement.';
                        setErrorMessage(msg);
                        resolve(false);
                    },
                },
            );
        });
    };

    const configured = Boolean(
        initialConfig?.placements && initialConfig.placements.length > 0,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[92vh] w-[min(1100px,96vw)] max-w-none flex-col gap-0 overflow-hidden p-0">
                <DialogHeader className="border-b border-border/60 px-5 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="space-y-1">
                            <DialogTitle className="flex items-center gap-2 text-base">
                                <PenLine className="h-4 w-4 text-primary" />
                                Signature Placement
                                {template ? `: ${template.name}` : null}
                            </DialogTitle>
                            <p className="text-xs text-muted-foreground">
                                Configure employee and optional company
                                signatory signature boxes on this draft version
                                only.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            {configured && !hasUnsavedChanges ? (
                                <Badge variant="secondary">Configured</Badge>
                            ) : hasUnsavedChanges ? (
                                <Badge variant="outline">Unsaved changes</Badge>
                            ) : (
                                <Badge variant="outline">Not configured</Badge>
                            )}
                            {version ? (
                                <Badge variant="outline">
                                    Draft v{version.version}
                                </Badge>
                            ) : null}
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-4">
                    {errorMessage ? (
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                            {errorMessage}
                        </div>
                    ) : null}

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="inline-flex rounded-lg border p-0.5">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        activeRole === 'subject'
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    onClick={() => handleRoleChange('subject')}
                                >
                                    Employee
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        activeRole === 'company_signatory'
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    disabled={!companyEnabled}
                                    onClick={() =>
                                        handleRoleChange('company_signatory')
                                    }
                                >
                                    Company signatory
                                </Button>
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={companyEnabled}
                                    onChange={(event) =>
                                        toggleCompanyPlacement(
                                            event.target.checked,
                                        )
                                    }
                                />
                                Enable company signatory placement
                            </label>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage <= 1 || isLoadingPdf}
                                onClick={() =>
                                    handlePageChange(currentPage - 1)
                                }
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Page {currentPage} of {totalPages}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={
                                    currentPage >= totalPages || isLoadingPdf
                                }
                                onClick={() =>
                                    handlePageChange(currentPage + 1)
                                }
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            disabled={isSaving || isLoadingPdf}
                            onClick={() => {
                                void handleSave();
                            }}
                        >
                            {isSaving ? (
                                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            ) : (
                                <Save className="mr-1.5 h-3.5 w-3.5" />
                            )}
                            Save placement
                        </Button>
                    </div>

                    <div
                        ref={containerRef}
                        className="relative min-h-[420px] flex-1 overflow-auto rounded-xl border border-border/60 bg-muted/20"
                    >
                        {isLoadingPdf ? (
                            <div className="absolute inset-0 z-10 flex items-center justify-center bg-background/60">
                                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : null}
                        <div className="flex justify-center p-4">
                            <canvas ref={canvasRef} />
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
