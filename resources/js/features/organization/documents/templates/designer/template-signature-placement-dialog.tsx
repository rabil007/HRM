import { router } from '@inertiajs/react';
import { Canvas, FabricImage, FabricText, Rect } from 'fabric';
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    PenLine,
    Plus,
    Save,
    Trash2,
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

type SignatureRole = SignaturePlacementItem['role'];

const SUBJECT_SLOT = 'subject';
const MAX_ROLE_OCCURRENCE = 7;
const DEFAULT_WIDTH = 0.25;
const DEFAULT_HEIGHT = 0.08;
const DEFAULT_X = 0.1;

function placementIdForSlot(slotKey: string): string {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject_signature';
    }

    const managerMatch = /^manager_(\d+)$/.exec(slotKey);

    if (managerMatch) {
        const occurrence = Number(managerMatch[1]);

        return occurrence === 1
            ? 'manager_signature'
            : `manager_signature_${occurrence}`;
    }

    const companyMatch = /^company_signatory_(\d+)$/.exec(slotKey);

    if (companyMatch) {
        const occurrence = Number(companyMatch[1]);

        return occurrence === 1
            ? 'company_signatory_signature'
            : `company_signatory_signature_${occurrence}`;
    }

    return `${slotKey}_signature`;
}

function roleForSlot(slotKey: string): SignatureRole {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject';
    }

    if (slotKey.startsWith('manager_')) {
        return 'manager';
    }

    return 'company_signatory';
}

function occurrenceForSlot(slotKey: string): number {
    if (slotKey === SUBJECT_SLOT) {
        return 1;
    }

    const match = /_(\d+)$/.exec(slotKey);

    return match ? Number(match[1]) : 1;
}

function slotKeyForRole(role: SignatureRole, occurrence: number): string {
    if (role === 'subject') {
        return SUBJECT_SLOT;
    }

    return role === 'manager'
        ? `manager_${occurrence}`
        : `company_signatory_${occurrence}`;
}

function defaultYForRole(role: SignatureRole, occurrence: number): number {
    if (role === 'subject') {
        return 0.75;
    }

    if (role === 'manager') {
        return Math.max(0.2, 0.62 - (occurrence - 1) * 0.1);
    }

    return Math.max(0.15, 0.5 - (occurrence - 1) * 0.1);
}

function slotLabel(slotKey: string): string {
    const role = roleForSlot(slotKey);
    const occurrence = occurrenceForSlot(slotKey);

    if (role === 'subject') {
        return 'Employee Signature';
    }

    if (role === 'manager') {
        return occurrence === 1
            ? 'Manager Signature'
            : `Manager Signature ${occurrence}`;
    }

    return occurrence === 1
        ? 'Company Signatory Signature'
        : `Company Signatory Signature ${occurrence}`;
}

function shortSlotLabel(slotKey: string): string {
    const role = roleForSlot(slotKey);
    const occurrence = occurrenceForSlot(slotKey);

    if (role === 'subject') {
        return 'Employee';
    }

    if (role === 'manager') {
        return occurrence === 1 ? 'Manager' : `Manager ${occurrence}`;
    }

    return occurrence === 1
        ? 'Company signatory'
        : `Company signatory ${occurrence}`;
}

function roleColors(role: SignatureRole): {
    fill: string;
    stroke: string;
    text: string;
} {
    if (role === 'subject') {
        return {
            fill: 'rgba(37, 99, 235, 0.28)',
            stroke: '#2563eb',
            text: '#1e3a8a',
        };
    }

    if (role === 'manager') {
        return {
            fill: 'rgba(5, 150, 105, 0.28)',
            stroke: '#059669',
            text: '#065f46',
        };
    }

    return {
        fill: 'rgba(180, 83, 9, 0.28)',
        stroke: '#b45309',
        text: '#78350f',
    };
}

function defaultPlacement(
    slotKey: string,
    page: number,
): SignaturePlacementItem {
    const role = roleForSlot(slotKey);
    const occurrence = occurrenceForSlot(slotKey);

    return {
        id: placementIdForSlot(slotKey),
        type: 'signature',
        role,
        slot_key: slotKey,
        page,
        x: DEFAULT_X,
        y: defaultYForRole(role, occurrence),
        width: DEFAULT_WIDTH,
        height: DEFAULT_HEIGHT,
        required: true,
    };
}

function normalizeLoadedPlacement(
    item: SignaturePlacementItem,
    slotKey: string,
): SignaturePlacementItem {
    return {
        id: item.id || placementIdForSlot(slotKey),
        type: 'signature',
        role: roleForSlot(slotKey),
        slot_key: slotKey,
        page: item.page || 1,
        x: item.x,
        y: item.y,
        width: item.width,
        height: item.height,
        required: item.required ?? true,
    };
}

function loadPlacementsFromConfig(
    initialConfig: SignaturePlacementConfig | null,
): Record<string, SignaturePlacementItem> {
    const placements: Record<string, SignaturePlacementItem> = {};
    const source = initialConfig?.placements ?? [];

    if (source.length === 0) {
        placements[SUBJECT_SLOT] = defaultPlacement(SUBJECT_SLOT, 1);

        return placements;
    }

    const isV2 =
        initialConfig?.schema_version === 2 ||
        source.some((item) => typeof item.slot_key === 'string');

    if (isV2) {
        for (const item of source) {
            const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);

            placements[slotKey] = normalizeLoadedPlacement(item, slotKey);
        }
    } else {
        for (const item of source) {
            const slotKey = slotKeyForRole(item.role, 1);
            placements[slotKey] = normalizeLoadedPlacement(item, slotKey);
        }
    }

    if (!placements[SUBJECT_SLOT]) {
        placements[SUBJECT_SLOT] = defaultPlacement(SUBJECT_SLOT, 1);
    }

    return placements;
}

function sortedSlotKeys(
    placements: Record<string, SignaturePlacementItem>,
): string[] {
    const keys = Object.keys(placements);

    return keys.sort((a, b) => {
        const roleOrder = (slot: string): number => {
            const role = roleForSlot(slot);

            return role === 'subject' ? 0 : role === 'manager' ? 1 : 2;
        };

        const roleDiff = roleOrder(a) - roleOrder(b);

        if (roleDiff !== 0) {
            return roleDiff;
        }

        return occurrenceForSlot(a) - occurrenceForSlot(b);
    });
}

function nextOccurrence(
    placements: Record<string, SignaturePlacementItem>,
    role: Exclude<SignatureRole, 'subject'>,
): number | null {
    const existing = Object.keys(placements)
        .filter((slot) => roleForSlot(slot) === role)
        .map(occurrenceForSlot)
        .sort((a, b) => a - b);

    const next = existing.length + 1;

    if (next > MAX_ROLE_OCCURRENCE) {
        return null;
    }

    return next;
}

function renumberRoleSlots(
    placements: Record<string, SignaturePlacementItem>,
    role: Exclude<SignatureRole, 'subject'>,
): Record<string, SignaturePlacementItem> {
    const roleSlots = sortedSlotKeys(placements).filter(
        (slot) => roleForSlot(slot) === role,
    );
    const next: Record<string, SignaturePlacementItem> = {};

    for (const [slotKey, item] of Object.entries(placements)) {
        if (roleForSlot(slotKey) !== role) {
            next[slotKey] = item;
        }
    }

    roleSlots.forEach((oldSlot, index) => {
        const occurrence = index + 1;
        const newSlot = slotKeyForRole(role, occurrence);
        const item = placements[oldSlot];

        if (!item) {
            return;
        }

        next[newSlot] = {
            ...item,
            id: placementIdForSlot(newSlot),
            role,
            slot_key: newSlot,
        };
    });

    return next;
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
    const [activeSlotKey, setActiveSlotKey] = useState(SUBJECT_SLOT);
    const [placement, setPlacement] = useState<SignaturePlacementItem>(
        defaultPlacement(SUBJECT_SLOT, 1),
    );
    const [slotKeys, setSlotKeys] = useState<string[]>([SUBJECT_SLOT]);
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
    const placementsBySlotRef = useRef<Record<string, SignaturePlacementItem>>(
        {},
    );
    const activeSlotKeyRef = useRef(activeSlotKey);
    const canvasSizeRef = useRef(canvasSize);
    // pdf.js document proxy — typed loosely to avoid coupling to pdfjs internals
    const pdfDocRef = useRef<{
        numPages: number;
        getPage: (pageNumber: number) => Promise<{
            getViewport: (params: { scale: number }) => {
                width: number;
                height: number;
            };
            render: (params: Record<string, unknown>) => {
                promise: Promise<unknown>;
            };
        }>;
    } | null>(null);

    useEffect(() => {
        placementRef.current = placement;
    }, [placement]);

    useEffect(() => {
        canvasSizeRef.current = canvasSize;
    }, [canvasSize]);

    useEffect(() => {
        activeSlotKeyRef.current = activeSlotKey;
    }, [activeSlotKey]);

    useEffect(() => {
        if (!open || !version) {
            return;
        }

        const loaded = loadPlacementsFromConfig(initialConfig);
        const keys = sortedSlotKeys(loaded);
        const subject =
            loaded[SUBJECT_SLOT] ?? defaultPlacement(SUBJECT_SLOT, 1);

        placementsBySlotRef.current = loaded;
        setSlotKeys(keys);
        setActiveSlotKey(SUBJECT_SLOT);
        activeSlotKeyRef.current = SUBJECT_SLOT;
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

    const syncLabel = useCallback((canvas: Canvas, slotKey: string) => {
        const label = labelRef.current;
        const placementId = placementIdForSlot(slotKey);
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

            const label = new FabricText(
                slotLabel(item.slot_key ?? SUBJECT_SLOT),
                {
                    left: pixel.left + 6,
                    top: pixel.top + 6,
                    fontSize: 12,
                    fontFamily: 'ui-sans-serif, system-ui, sans-serif',
                    fill: colors.text,
                    selectable: false,
                    evented: false,
                },
            );
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
                const slotKey = activeSlotKeyRef.current;
                const placementId = placementIdForSlot(slotKey);
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
                placementsBySlotRef.current[slotKey] = updated;
                setPlacement(updated);
                setHasUnsavedChanges(true);
                syncLabel(canvas, slotKey);
            };

            canvas.off('object:modified');
            canvas.off('object:moving');
            canvas.off('object:scaling');
            canvas.on('object:modified', persistFromCanvas);
            canvas.on('object:moving', () =>
                syncLabel(canvas, activeSlotKeyRef.current),
            );
            canvas.on('object:scaling', () =>
                syncLabel(canvas, activeSlotKeyRef.current),
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
                    pdf = (await pdfjs.getDocument({ data })
                        .promise) as unknown as NonNullable<
                        typeof pdfDocRef.current
                    >;

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
        activeSlotKey,
        placement,
        attachCanvasEvents,
        syncPlacementRect,
    ]);

    const handleSlotChange = (slotKey: string) => {
        placementsBySlotRef.current[activeSlotKey] = placementRef.current;

        const next =
            placementsBySlotRef.current[slotKey] ??
            defaultPlacement(slotKey, currentPage);

        placementsBySlotRef.current[slotKey] = next;
        activeSlotKeyRef.current = slotKey;
        setActiveSlotKey(slotKey);
        setPlacement(next);
        placementRef.current = next;
        setHasUnsavedChanges(true);
    };

    const addRoleSlot = (role: Exclude<SignatureRole, 'subject'>) => {
        placementsBySlotRef.current[activeSlotKey] = placementRef.current;

        const occurrence = nextOccurrence(placementsBySlotRef.current, role);

        if (occurrence === null) {
            setErrorMessage(
                `At most ${MAX_ROLE_OCCURRENCE} ${role === 'manager' ? 'manager' : 'company signatory'} signature boxes are allowed.`,
            );

            return;
        }

        const slotKey = slotKeyForRole(role, occurrence);
        const next = defaultPlacement(slotKey, currentPage);
        placementsBySlotRef.current[slotKey] = next;

        const keys = sortedSlotKeys(placementsBySlotRef.current);
        setSlotKeys(keys);
        activeSlotKeyRef.current = slotKey;
        setActiveSlotKey(slotKey);
        setPlacement(next);
        placementRef.current = next;
        setErrorMessage(null);
        setHasUnsavedChanges(true);
    };

    const removeSlot = (slotKey: string) => {
        if (slotKey === SUBJECT_SLOT) {
            return;
        }

        const role = roleForSlot(slotKey) as Exclude<SignatureRole, 'subject'>;
        const removedOccurrence = occurrenceForSlot(slotKey);
        const activeBefore = activeSlotKeyRef.current;
        const activeRole = roleForSlot(activeBefore);
        const activeOccurrence = occurrenceForSlot(activeBefore);

        placementsBySlotRef.current[activeBefore] = placementRef.current;
        delete placementsBySlotRef.current[slotKey];
        placementsBySlotRef.current = renumberRoleSlots(
            placementsBySlotRef.current,
            role,
        );

        const keys = sortedSlotKeys(placementsBySlotRef.current);
        let nextActive = SUBJECT_SLOT;

        if (activeBefore !== slotKey) {
            if (activeRole === role) {
                const shiftedOccurrence =
                    activeOccurrence > removedOccurrence
                        ? activeOccurrence - 1
                        : activeOccurrence;
                const candidate = slotKeyForRole(role, shiftedOccurrence);

                if (keys.includes(candidate)) {
                    nextActive = candidate;
                }
            } else if (keys.includes(activeBefore)) {
                nextActive = activeBefore;
            }
        }

        const nextPlacement =
            placementsBySlotRef.current[nextActive] ??
            defaultPlacement(SUBJECT_SLOT, currentPage);

        setSlotKeys(keys);
        activeSlotKeyRef.current = nextActive;
        setActiveSlotKey(nextActive);
        setPlacement(nextPlacement);
        placementRef.current = nextPlacement;
        setHasUnsavedChanges(true);
    };

    const handlePageChange = (nextPage: number) => {
        if (nextPage < 1 || nextPage > totalPages) {
            return;
        }

        const updated = {
            ...placementRef.current,
            page: nextPage,
        };
        placementRef.current = updated;
        placementsBySlotRef.current[activeSlotKeyRef.current] = updated;
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

        placementsBySlotRef.current[activeSlotKeyRef.current] =
            placementRef.current;

        const placements: SignaturePlacementItem[] = sortedSlotKeys(
            placementsBySlotRef.current,
        ).map((slotKey) => {
            const item =
                placementsBySlotRef.current[slotKey] ??
                defaultPlacement(slotKey, 1);

            return {
                ...item,
                id: placementIdForSlot(slotKey),
                type: 'signature',
                role: roleForSlot(slotKey),
                slot_key: slotKey,
                required: true,
            };
        });

        const payload: SignaturePlacementConfig = {
            schema_version: 2,
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
    const managerSlots = slotKeys.filter(
        (slot) => roleForSlot(slot) === 'manager',
    );
    const companySlots = slotKeys.filter(
        (slot) => roleForSlot(slot) === 'company_signatory',
    );
    const canAddManager = managerSlots.length < MAX_ROLE_OCCURRENCE;
    const canAddCompany = companySlots.length < MAX_ROLE_OCCURRENCE;

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
                                Place employee, manager, and company signatory
                                signature boxes for multi-stage signing on this
                                draft version only.
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
                            <div className="inline-flex max-w-full flex-wrap rounded-lg border p-0.5">
                                {slotKeys.map((slotKey) => (
                                    <div
                                        key={slotKey}
                                        className="flex items-center"
                                    >
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                activeSlotKey === slotKey
                                                    ? 'default'
                                                    : 'ghost'
                                            }
                                            onClick={() =>
                                                handleSlotChange(slotKey)
                                            }
                                        >
                                            {shortSlotLabel(slotKey)}
                                        </Button>
                                        {slotKey !== SUBJECT_SLOT ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                                onClick={() =>
                                                    removeSlot(slotKey)
                                                }
                                                aria-label={`Remove ${shortSlotLabel(slotKey)}`}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!canAddManager}
                                onClick={() => addRoleSlot('manager')}
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Add Manager Signature
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!canAddCompany}
                                onClick={() => addRoleSlot('company_signatory')}
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                Add Company Signatory Signature
                            </Button>
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
