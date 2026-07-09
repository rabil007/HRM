import { ImageUp, PenLine } from 'lucide-react';
import { useRef, useState } from 'react';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    fileToSignatureDataUrl,
    isAllowedSignatureImage,
} from '@/features/esign/signature-image';
import { cn } from '@/lib/utils';

type Mode = 'draw' | 'upload';

type Props = {
    clearToken: number;
    onChange: (dataUrl: string | null) => void;
    onModeChange?: (mode: Mode) => void;
    previewUrl?: string | null;
    className?: string;
    drawCanvasClassName?: string;
    drawLineWidth?: number;
    showDrawPad?: boolean;
};

export function SignatureCapture({
    clearToken,
    onChange,
    onModeChange,
    previewUrl = null,
    className,
    drawCanvasClassName = 'h-48',
    drawLineWidth = 3,
    showDrawPad = true,
}: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [mode, setMode] = useState<Mode>('draw');
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [isReading, setIsReading] = useState(false);

    const handleModeChange = (value: string) => {
        const next = value === 'upload' ? 'upload' : 'draw';
        setMode(next);
        setUploadError(null);
        onModeChange?.(next);
        onChange(null);
    };

    const handleFile = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        if (!isAllowedSignatureImage(file)) {
            setUploadError('Please choose a PNG, JPG, or WebP image.');

            return;
        }

        setIsReading(true);
        setUploadError(null);

        try {
            const dataUrl = await fileToSignatureDataUrl(file);
            onChange(dataUrl);
        } catch (error) {
            onChange(null);
            setUploadError(
                error instanceof Error
                    ? error.message
                    : 'Could not read that image. Try another file.',
            );
        } finally {
            setIsReading(false);

            if (inputRef.current) {
                inputRef.current.value = '';
            }
        }
    };

    return (
        <div className={cn('space-y-3', className)}>
            <Tabs value={mode} onValueChange={handleModeChange}>
                <TabsList className="grid h-11 w-full grid-cols-2">
                    <TabsTrigger value="draw" className="gap-1.5 text-sm">
                        <PenLine className="size-4" />
                        Draw
                    </TabsTrigger>
                    <TabsTrigger value="upload" className="gap-1.5 text-sm">
                        <ImageUp className="size-4" />
                        Upload image
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="draw" className="mt-3 space-y-2">
                    {showDrawPad ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Sign inside the white box below.
                            </p>
                            <SignaturePad
                                key={`draw-${clearToken}`}
                                onChange={onChange}
                                className="w-full"
                                canvasClassName={drawCanvasClassName}
                                lineWidth={drawLineWidth}
                                hideClear
                            />
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Draw on the highlighted signature area in the
                            document.
                        </p>
                    )}
                </TabsContent>

                <TabsContent value="upload" className="mt-3 space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Use a clear photo or scan of your signature (PNG, JPG,
                        or WebP).
                    </p>

                    <input
                        ref={inputRef}
                        type="file"
                        accept="image/png,image/jpeg,image/jpg,image/webp"
                        className="sr-only"
                        onChange={(event) => {
                            void handleFile(event.target.files?.[0]);
                        }}
                    />

                    <Button
                        type="button"
                        variant="outline"
                        className="h-12 w-full text-base"
                        disabled={isReading}
                        onClick={() => inputRef.current?.click()}
                    >
                        <ImageUp className="mr-2 size-4" />
                        {isReading
                            ? 'Reading image…'
                            : previewUrl
                              ? 'Replace signature image'
                              : 'Choose signature image'}
                    </Button>

                    {uploadError ? (
                        <p className="text-sm text-destructive">{uploadError}</p>
                    ) : null}

                    {previewUrl ? (
                        <div className="overflow-hidden rounded-xl border bg-white p-4">
                            <p className="mb-2 text-xs font-medium text-muted-foreground">
                                Preview
                            </p>
                            <img
                                src={previewUrl}
                                alt="Uploaded signature preview"
                                className="mx-auto h-28 w-full object-contain"
                            />
                        </div>
                    ) : null}
                </TabsContent>
            </Tabs>
        </div>
    );
}
