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
            setUploadError('Use a PNG, JPG, or WebP image.');
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
                    : 'Could not read signature image.',
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
                <TabsList className="grid w-full grid-cols-2">
                    <TabsTrigger value="draw" className="gap-1.5">
                        <PenLine className="size-4" />
                        Draw
                    </TabsTrigger>
                    <TabsTrigger value="upload" className="gap-1.5">
                        <ImageUp className="size-4" />
                        Upload
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="draw" className="mt-3 space-y-2">
                    {showDrawPad ? (
                        <>
                            <p className="text-xs text-muted-foreground">
                                Draw with your finger or mouse. It appears on both
                                signature lines in the document.
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
                        <p className="text-xs text-muted-foreground">
                            Draw directly on the highlighted signature area in the
                            document above.
                        </p>
                    )}
                </TabsContent>

                <TabsContent value="upload" className="mt-3 space-y-3">
                    <p className="text-xs text-muted-foreground">
                        Upload a PNG, JPG, or WebP of your signature. It will show
                        in the signature placeholders.
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
                        className="h-11 w-full"
                        disabled={isReading}
                        onClick={() => inputRef.current?.click()}
                    >
                        <ImageUp className="mr-2 size-4" />
                        {isReading ? 'Reading image…' : 'Choose signature image'}
                    </Button>

                    {uploadError ? (
                        <p className="text-sm text-destructive">{uploadError}</p>
                    ) : null}

                    {previewUrl ? (
                        <div className="overflow-hidden rounded-lg border bg-white p-3">
                            <img
                                src={previewUrl}
                                alt="Uploaded signature preview"
                                className="mx-auto h-24 w-full object-contain"
                            />
                        </div>
                    ) : null}
                </TabsContent>
            </Tabs>
        </div>
    );
}
