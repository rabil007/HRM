import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { formatSignedDate } from '@/features/esign/format-signed-date';
import {
    placementPercentOverlaysFromConfig,
    type SignaturePlacementConfig,
} from '@/features/settings/esign-placement/esign-placement-coordinates';
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

export function PdfSignatureViewer({
    pdfUrl,
    page = 1,
    placement,
    onSignatureChange,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const pdfCanvasRef = useRef<HTMLCanvasElement>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [clearToken, setClearToken] = useState(0);
    const [signaturePreview, setSignaturePreview] = useState<string | null>(
        null,
    );

    const overlays = useMemo(
        () => placementPercentOverlaysFromConfig(placement),
        [placement],
    );
    const signedDate = formatSignedDate();

    useEffect(() => {
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
                const container = containerRef.current;
                const canvas = pdfCanvasRef.current;

                if (!container || !canvas || cancelled) {
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
    }, [page, pdfUrl]);

    const handleSignatureChange = (dataUrl: string | null) => {
        setSignaturePreview(dataUrl);
        onSignatureChange(dataUrl);
    };

    const handleClear = () => {
        setClearToken((value) => value + 1);
        setSignaturePreview(null);
        onSignatureChange(null);
    };

    return (
        <div className="space-y-3">
            <div
                ref={containerRef}
                className="relative w-full overflow-hidden rounded-lg border bg-muted/20"
            >
                {isLoading ? (
                    <div className="absolute inset-0 z-10 flex min-h-[420px] items-center justify-center bg-muted/20">
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
                            className="pointer-events-none absolute flex items-center px-1 text-sm font-semibold text-foreground"
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
                            className="pointer-events-none absolute flex items-center px-1 text-sm font-semibold text-foreground"
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
                            className="absolute border-2 border-dashed border-primary/60 bg-primary/5"
                            style={{
                                left: overlays.signature.left,
                                top: overlays.signature.top,
                                width: overlays.signature.width,
                                height: overlays.signature.height,
                            }}
                        >
                            <SignaturePad
                                key={clearToken}
                                fill
                                onChange={handleSignatureChange}
                                className="h-full"
                            />
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

            {!isLoading && !error ? (
                <p className="text-xs text-muted-foreground">
                    Draw your signature in the highlighted English area. The
                    same signature preview appears on the Arabic side, and
                    today&apos;s date ({signedDate}) is shown in both date
                    fields.
                </p>
            ) : null}

            {!isLoading && !error ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={handleClear}
                >
                    Clear signature
                </Button>
            ) : null}
        </div>
    );
}
