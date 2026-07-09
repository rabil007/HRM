import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { formatSignedDate } from '@/features/esign/format-signed-date';
import { SignatureCapture } from '@/features/esign/signature-capture';
import {
    placementPercentOverlaysFromConfig,
    type SignaturePlacementConfig,
} from '@/features/settings/esign-placement/esign-placement-coordinates';
import { useIsMobile } from '@/hooks/use-mobile';
import { getPdfJs } from '@/lib/pdfjs';

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
    onSignatureChange: (dataUrl: string | null) => void;
};

const MOBILE_PDF_MIN_WIDTH = 720;

export function PdfSignatureViewer({
    pdfUrl,
    page = 1,
    placement,
    onSignatureChange,
}: Props) {
    const isMobile = useIsMobile();
    const viewportRef = useRef<HTMLDivElement>(null);
    const pdfCanvasRef = useRef<HTMLCanvasElement>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [clearToken, setClearToken] = useState(0);
    const [signaturePreview, setSignaturePreview] = useState<string | null>(
        null,
    );
    const [captureMode, setCaptureMode] = useState<'draw' | 'upload'>('draw');
    const [renderWidth, setRenderWidth] = useState(0);

    const overlays = useMemo(
        () => placementPercentOverlaysFromConfig(placement),
        [placement],
    );
    const signedDate = formatSignedDate();
    const useOverlayPad = !isMobile && captureMode === 'draw';

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
    }, [isMobile]);

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
        setSignaturePreview(dataUrl);
        onSignatureChange(dataUrl);
    };

    const handleClear = () => {
        setClearToken((value) => value + 1);
        setSignaturePreview(null);
        onSignatureChange(null);
    };

    const handleModeChange = (mode: 'draw' | 'upload') => {
        setCaptureMode(mode);
        setClearToken((value) => value + 1);
        setSignaturePreview(null);
        onSignatureChange(null);
    };

    return (
        <div className="space-y-4">
            {isMobile ? (
                <p className="text-sm text-muted-foreground">
                    Scroll the document to review it, then draw or upload your
                    signature below. Today&apos;s date ({signedDate}) is applied
                    automatically.
                </p>
            ) : null}

            <div
                ref={viewportRef}
                className={
                    isMobile
                        ? 'max-h-[55svh] w-full overflow-auto overscroll-contain rounded-lg border bg-muted/20 touch-pan-x touch-pan-y'
                        : 'w-full'
                }
            >
                <div
                    className={
                        isMobile
                            ? 'relative bg-muted/20'
                            : 'relative w-full overflow-hidden rounded-lg border bg-muted/20'
                    }
                    style={
                        isMobile && renderWidth > 0
                            ? { width: renderWidth }
                            : undefined
                    }
                >
                    {isLoading ? (
                        <div className="absolute inset-0 z-10 flex min-h-[280px] items-center justify-center bg-muted/20 sm:min-h-[420px]">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : null}

                    {error ? (
                        <div className="flex min-h-[200px] items-center justify-center p-6 text-center text-sm text-destructive">
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
                                className={
                                    useOverlayPad
                                        ? 'absolute border-2 border-dashed border-primary/60 bg-primary/5'
                                        : 'pointer-events-none absolute overflow-hidden border-2 border-dashed border-primary/60 bg-primary/5'
                                }
                                style={{
                                    left: overlays.signature.left,
                                    top: overlays.signature.top,
                                    width: overlays.signature.width,
                                    height: overlays.signature.height,
                                }}
                            >
                                {useOverlayPad ? (
                                    <SignaturePad
                                        key={clearToken}
                                        fill
                                        hideClear
                                        onChange={handleSignatureChange}
                                        className="h-full"
                                    />
                                ) : signaturePreview ? (
                                    <img
                                        src={signaturePreview}
                                        alt="Signature preview"
                                        className="h-full w-full object-contain p-1"
                                    />
                                ) : (
                                    <span className="flex h-full items-center justify-center px-1 text-center text-[10px] font-medium text-primary">
                                        {captureMode === 'upload'
                                            ? 'Upload below'
                                            : 'Sign below'}
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
                                {signaturePreview ? (
                                    <img
                                        src={signaturePreview}
                                        alt="Arabic signature preview"
                                        className="h-full w-full object-contain p-1"
                                    />
                                ) : null}
                            </div>
                        </>
                    ) : null}
                </div>
            </div>

            {!isLoading && !error ? (
                <div className="space-y-3 rounded-xl border bg-background p-3 shadow-sm">
                    <div className="space-y-1">
                        <p className="text-sm font-medium">Your signature</p>
                        <p className="text-xs text-muted-foreground">
                            Draw or upload a signature image. It appears in both
                            placeholders, and today&apos;s date ({signedDate}) is
                            applied automatically.
                        </p>
                    </div>
                    <SignatureCapture
                        clearToken={clearToken}
                        onChange={handleSignatureChange}
                        onModeChange={handleModeChange}
                        previewUrl={signaturePreview}
                        showDrawPad={isMobile}
                        drawCanvasClassName={isMobile ? 'h-48' : 'h-40'}
                        drawLineWidth={isMobile ? 3 : 2}
                    />
                </div>
            ) : null}

            {!isLoading && !error ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full sm:w-auto"
                    onClick={handleClear}
                >
                    Clear signature
                </Button>
            ) : null}
        </div>
    );
}
