import { router } from '@inertiajs/react';
import { ImageIcon, Trash2, Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    assetKey: string;
    currentUrl?: string | null;
    accept?: string;
    hint?: string;
    onFileChange: (file: File | null) => void;
    onRemove?: () => void;
    error?: string;
};

export function BrandingUploadField({
    label,
    assetKey,
    currentUrl,
    accept = 'image/png,image/jpeg,image/jpg,image/svg+xml',
    hint,
    onFileChange,
    onRemove,
    error,
}: Props) {
    const inputId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);

    const displayUrl = preview ?? currentUrl ?? null;

    function handleFileChange(file: File | null) {
        onFileChange(file);

        if (preview) {
            URL.revokeObjectURL(preview);
        }

        setPreview(file ? URL.createObjectURL(file) : null);
    }

    function removeImage() {
        if (onRemove) {
            onRemove();

            return;
        }

        router.delete(`/settings/application/branding/${assetKey}`, {
            preserveScroll: true,
        });
    }

    return (
        <div className="space-y-2">
            <Label
                htmlFor={inputId}
                className="text-xs font-semibold tracking-wider text-muted-foreground/80 uppercase"
            >
                {label}
            </Label>

            <div
                className={cn(
                    'flex flex-col gap-4 rounded-xl border border-border/60 bg-card/50 p-4 sm:flex-row sm:items-center',
                    error && 'border-destructive/50',
                )}
            >
                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted/40">
                    {displayUrl ? (
                        <img
                            src={displayUrl}
                            alt={`${label} preview`}
                            className="max-h-full max-w-full object-contain p-1"
                        />
                    ) : (
                        <ImageIcon className="size-8 text-muted-foreground/40" />
                    )}
                </div>

                <div className="flex flex-1 flex-col gap-2">
                    {hint ? (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    ) : null}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => inputRef.current?.click()}
                        >
                            <Upload className="size-3.5" />
                            {displayUrl ? 'Replace' : 'Upload'}
                        </Button>
                        {currentUrl && !preview ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={removeImage}
                            >
                                <Trash2 className="size-3.5" />
                                Remove
                            </Button>
                        ) : null}
                    </div>
                </div>

                <input
                    ref={inputRef}
                    id={inputId}
                    type="file"
                    accept={accept}
                    className="sr-only"
                    onChange={(e) =>
                        handleFileChange(e.target.files?.[0] ?? null)
                    }
                />
            </div>

            {error ? (
                <p className="text-xs font-medium text-destructive">{error}</p>
            ) : null}
        </div>
    );
}
