import { CheckCircle2, FileText, PenLine } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatSignedDate } from '@/features/esign/format-signed-date';
import { SignatureCapture } from '@/features/esign/signature-capture';
import {
    placementPercentOverlaysFromConfig
    
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import type {SignaturePlacementConfig} from '@/features/settings/esign-placement/esign-placement-coordinates';
import { useIsMobile } from '@/hooks/use-mobile';
import { getPdfJs } from '@/lib/pdfjs';
import { cn } from '@/lib/utils';

export type SignatureOverlayRect = {
    left: string;
    top: string;
    width: string;
    height: string;
};

type Props = {
    pdfUrl: string;
    page?: number;
    placement: SignaturePlacementConfig;
    mode: 'review' | 'sign';
    signatureData: string | null;
    onSignatureChange: (dataUrl: string | null) => void;
};

const MOBILE_PDF_MIN_WIDTH = 720;

export function PdfSignatureViewer({
    pdfUrl,
    page = 1,
    placement,
    mode,
    signatureData,
    onSignatureChange,
}: Props) {
    const isMobile = useIsMobile();
    const viewportRef = useRef<HTMLDivElement>(null);
    const pdfCanvasRef = useRef<HTMLCanvasElement>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [clearToken, setClearToken] = useState(0);
    const [renderWidth, setRenderWidth] = useState(0);

    const overlays = useMemo(
        () => placementPercentOverlaysFromConfig(placement),
        [placement],
    );
    const signedDate = formatSignedDate();
    const isReview = mode === 'review';

    useEffect(() => {
        const viewport = viewportRef.current;

        if (!viewport) {
            return;
        }

        const updateWidth = () => {
            const available = viewport.clientWidth;

            if (available <= 0) {
                return;
            }

            setRenderWidth(
                isMobile
                    ? Math.max(available, MOBILE_PDF_MIN_WIDTH)
                    : available,
            );
        };

        updateWidth();

        const observer = new ResizeObserver(updateWidth);
        observer.observe(viewport);

        return () => observer.disconnect();
    }, [isMobile, mode]);

    useEffect(() => {
        if (renderWidth <= 0) {
            return;
        }

        let cancelled = false;

        const render = async () => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await fetch(pdfUrl);

                if (!response.ok) {
                    throw new Error('Failed to load PDF.');
                }

                const data = await response.arrayBuffer();
                const pdfjs = await getPdfJs();
                const pdf = await pdfjs.getDocument({ data }).promise;
                const pdfPage = await pdf.getPage(page);
                const canvas = pdfCanvasRef.current;

                if (!canvas || cancelled) {
                    return;
                }

                const baseViewport = pdfPage.getViewport({ scale: 1 });
                const scale = renderWidth / baseViewport.width;
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
                    setIsLoading(false);
                }
            } catch (loadError) {
                if (!cancelled) {
                    setError(
                        loadError instanceof Error
                            ? loadError.message
                            : 'Failed to load PDF.',
                    );
                    setIsLoading(false);
                }
            }
        };

        void render();

        return () => {
            cancelled = true;
        };
    }, [page, pdfUrl, renderWidth]);

    const handleSignatureChange = (dataUrl: string | null) => {
        onSignatureChange(dataUrl);
    };

    const handleClear = () => {
        setClearToken((value) => value + 1);
        onSignatureChange(null);
    };

    const handleModeChange = () => {
        setClearToken((value) => value + 1);
        onSignatureChange(null);
    };

    return (
        <div className="space-y-3">
            {/* Desktop heading */}
            <div className="hidden items-start gap-3 sm:flex">
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                    {isReview ? (
                        <FileText className="size-4 text-muted-foreground" />
                    ) : (
                        <PenLine className="size-4 text-muted-foreground" />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-base font-semibold">
                            {isReview ? 'Review the document' : 'Add your signature'}
                        </h2>
                        {!isReview ? (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                    signatureData
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
                                )}
                            >
                                {signatureData ? <CheckCircle2 className="size-3" /> : null}
                                {signatureData ? 'Added' : 'Required'}
                            </span>
                        ) : null}
                    </div>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {isReview
                            ? "Highlighted boxes show where your signature and today's date will appear."
                            : `Draw or upload your signature. Date ${signedDate} is added automatically.`}
                    </p>
                </div>
            </div>

            {/* Mobile heading */}
            <div className="sm:hidden">
                <div className="flex items-center gap-2">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10">
                        {isReview ? (
                            <FileText className="size-3.5 text-primary" />
                        ) : (
                            <PenLine className="size-3.5 text-primary" />
                        )}
                    </span>
                    <div className="flex min-w-0 flex-1 items-center gap-2">
                        <p className="text-sm font-semibold">
                            {isReview ? 'Read the document' : 'Add your signature'}
                        </p>
                        {!isReview ? (
                            <span
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                                    signatureData
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
                                )}
                            >
                                {signatureData ? <CheckCircle2 className="size-3" /> : null}
                                {signatureData ? 'Saved' : 'Required'}
                            </span>
                        ) : null}
                    </div>
                </div>
                {isReview ? (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Scroll to read the full declaration. Highlighted areas show where your signature goes.
                    </p>
                ) : (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Draw or upload — date {signedDate} is added automatically.
                    </p>
                )}
            </div>

            {/* PDF viewport with scroll-fade on mobile */}
            <div className="relative">
                <div
                    ref={viewportRef}
                    className={
                        isMobile
                        ? cn(
                              'w-full overflow-auto rounded-xl border shadow-sm touch-pan-x touch-pan-y',
                              isReview
                                  ? 'h-[calc(100svh-14rem)] max-h-[calc(100svh-14rem)]'
                                  : 'max-h-[30svh]',
                          )
                            : cn(
                                  'w-full overflow-hidden rounded-xl border shadow-sm',
                                  !isReview && 'max-h-[420px] overflow-auto',
                              )
                    }
                >
                    <div
                        className="relative bg-white"
                        style={
                            isMobile && renderWidth > 0
                                ? { width: renderWidth }
                                : undefined
                        }
                    >
                        {isLoading ? (
                            <div className="flex min-h-[160px] items-center justify-center bg-muted/10 sm:min-h-[360px]">
                                <div className="flex flex-col items-center gap-2.5">
                                    <div className="relative">
                                        <div className="size-10 animate-spin rounded-full border-2 border-muted border-t-primary" />
                                    </div>
                                    <p className="text-xs text-muted-foreground">Loading document…</p>
                                </div>
                            </div>
                        ) : null}

                        {error ? (
                            <div className="flex min-h-[160px] items-center justify-center p-6 text-center text-sm text-destructive">
                                {error}
                            </div>
                        ) : null}

                        <canvas
                            ref={pdfCanvasRef}
                            className={
                                error
                                    ? 'hidden'
                                    : isLoading
                                      ? 'invisible block h-auto w-full'
                                      : 'block h-auto w-full'
                            }
                        />

                        {!isLoading && !error ? (
                            <>
                                <div
                                    className="pointer-events-none absolute flex items-center px-1 text-sm font-semibold text-[#1a1a1a]"
                                    style={{
                                        left: overlays.date.left,
                                        top: overlays.date.top,
                                        width: overlays.date.width,
                                        height: overlays.date.height,
                                    }}
                                >
                                    {signedDate}
                                </div>

                                <div
                                    className="pointer-events-none absolute flex items-center px-1 text-sm font-semibold text-[#1a1a1a]"
                                    style={{
                                        left: overlays.date_ar.left,
                                        top: overlays.date_ar.top,
                                        width: overlays.date_ar.width,
                                        height: overlays.date_ar.height,
                                    }}
                                >
                                    {signedDate}
                                </div>

                                <div
                                    className="pointer-events-none absolute overflow-hidden border-2 border-dashed border-primary/60 bg-primary/5"
                                    style={{
                                        left: overlays.signature.left,
                                        top: overlays.signature.top,
                                        width: overlays.signature.width,
                                        height: overlays.signature.height,
                                    }}
                                >
                                    {signatureData ? (
                                        <img
                                            src={signatureData}
                                            alt="Signature preview"
                                            className="h-full w-full object-contain p-1"
                                        />
                                    ) : (
                                        <span className="flex h-full items-center justify-center px-1 text-center text-[10px] font-medium text-primary">
                                            Signature
                                        </span>
                                    )}
                                </div>

                                <div
                                    className="pointer-events-none absolute overflow-hidden border border-dashed border-muted-foreground/50 bg-background/40"
                                    style={{
                                        left: overlays.signature_ar.left,
                                        top: overlays.signature_ar.top,
                                        width: overlays.signature_ar.width,
                                        height: overlays.signature_ar.height,
                                    }}
                                >
                                    {signatureData ? (
                                        <img
                                            src={signatureData}
                                            alt="Arabic signature preview"
                                            className="h-full w-full object-contain p-1"
                                        />
                                    ) : null}
                                </div>
                            </>
                        ) : null}
                    </div>
                </div>

                {/* Scroll-fade gradient — only on mobile review when loaded */}
                {isMobile && isReview && !isLoading && !error ? (
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 h-10 rounded-b-xl bg-linear-to-t from-background/80 to-transparent" />
                ) : null}
            </div>

            {isMobile && isReview && !isLoading && !error ? (
                <p className="text-[11px] text-muted-foreground">
                    Scroll inside the document, then tap Continue when ready.
                </p>
            ) : null}

            {!isReview && !isLoading && !error ? (
                <div className="space-y-2">
                    <SignatureCapture
                        clearToken={clearToken}
                        onChange={handleSignatureChange}
                        onModeChange={handleModeChange}
                        previewUrl={signatureData}
                        showDrawPad
                        drawCanvasClassName={isMobile ? 'h-44' : 'h-44'}
                        drawLineWidth={isMobile ? 3 : 2}
                    />

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full sm:w-auto"
                        onClick={handleClear}
                        disabled={!signatureData}
                    >
                        Clear signature
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
