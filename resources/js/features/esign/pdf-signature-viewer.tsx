import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
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
    overlay: SignatureOverlayRect;
    onSignatureChange: (dataUrl: string | null) => void;
};

export function PdfSignatureViewer({
    pdfUrl,
    page = 1,
    overlay,
    onSignatureChange,
}: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const pdfCanvasRef = useRef<HTMLCanvasElement>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [clearToken, setClearToken] = useState(0);

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

    return (
        <div className="space-y-3">
            <div
                ref={containerRef}
                className="relative w-full overflow-hidden rounded-lg border bg-muted/20"
            >
                {isLoading ? (
                    <div className="flex min-h-[420px] items-center justify-center">
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
                    className={isLoading || error ? 'hidden' : 'block h-auto w-full'}
                />

                {!isLoading && !error ? (
                    <div
                        className="absolute border-2 border-dashed border-primary/60 bg-primary/5"
                        style={{
                            left: overlay.left,
                            top: overlay.top,
                            width: overlay.width,
                            height: overlay.height,
                        }}
                    >
                        <SignaturePad
                            key={clearToken}
                            fill
                            onChange={onSignatureChange}
                            className="h-full"
                        />
                    </div>
                ) : null}
            </div>

            {!isLoading && !error ? (
                <p className="text-xs text-muted-foreground">
                    Draw your signature in the highlighted area on the English
                    signature line. The same signature will appear on both
                    language columns after submission.
                </p>
            ) : null}

            {!isLoading && !error ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setClearToken((value) => value + 1);
                        onSignatureChange(null);
                    }}
                >
                    Clear signature
                </Button>
            ) : null}
        </div>
    );
}
