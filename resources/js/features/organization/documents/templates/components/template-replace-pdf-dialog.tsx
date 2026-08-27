import { router } from '@inertiajs/react';
import { AlertTriangle, FileUp, Loader2, UploadCloud, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { CustomTemplate, TemplateVersionSummary } from '../types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: CustomTemplate | null;
    version: TemplateVersionSummary | null;
};

export function TemplateReplacePdfDialog({
    open,
    onOpenChange,
    template,
    version,
}: Props) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const resetForm = () => {
        setFile(null);
        setErrorMessage(null);
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0] || null;

        if (!selected) {
            return;
        }

        if (
            selected.type !== 'application/pdf' &&
            !selected.name.endsWith('.pdf')
        ) {
            setErrorMessage('The selected file must be a PDF document.');

            return;
        }

        if (selected.size > 20 * 1024 * 1024) {
            setErrorMessage('The selected PDF exceeds the 20 MB size limit.');

            return;
        }

        setErrorMessage(null);
        setFile(selected);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!template || !version) {
            return;
        }

        if (!file) {
            setErrorMessage('Please select a replacement PDF file.');

            return;
        }

        setIsSubmitting(true);
        setErrorMessage(null);

        const formData = new FormData();
        formData.append('file', file);

        router.post(
            `/organization/documents/templates/${template.id}/versions/${version.id}/replace-pdf`,
            formData,
            {
                forceFormData: true,
                onSuccess: () => {
                    setIsSubmitting(false);
                    resetForm();
                    onOpenChange(false);
                },
                onError: (errors) => {
                    setIsSubmitting(false);
                    const firstError = Object.values(errors)[0];
                    setErrorMessage(
                        typeof firstError === 'string'
                            ? firstError
                            : 'Failed to replace PDF.',
                    );
                },
            },
        );
    };

    const formatBytes = (bytes: number) => {
        if (bytes === 0) {
            return '0 B';
        }

        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    };

    if (!template || !version) {
        return null;
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!isSubmitting) {
                    onOpenChange(next);

                    if (!next) {
                        resetForm();
                    }
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={handleSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Replace Template PDF</DialogTitle>
                        <DialogDescription>
                            Replace the source PDF document for Draft v
                            {version.version} of{' '}
                            <span className="font-semibold text-foreground">
                                {template.name}
                            </span>
                            .
                        </DialogDescription>
                    </DialogHeader>

                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                        <div className="flex items-start gap-2.5">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p className="font-semibold">
                                    Placements will be reset
                                </p>
                                <p className="mt-0.5 text-amber-700 dark:text-amber-400">
                                    Replacing the PDF will clear any configured
                                    merge-field placements on this draft version
                                    to avoid misaligned coordinates.
                                </p>
                            </div>
                        </div>
                    </div>

                    {errorMessage && (
                        <div className="rounded-lg bg-destructive/10 p-3 text-xs font-medium text-destructive">
                            {errorMessage}
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label>
                            Replacement PDF{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".pdf,application/pdf"
                            onChange={handleFileChange}
                            className="hidden"
                            disabled={isSubmitting}
                        />

                        {!file ? (
                            <div
                                onClick={() => fileInputRef.current?.click()}
                                className="flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-border/80 p-6 text-center transition-colors hover:border-primary/50 hover:bg-muted/30"
                            >
                                <div className="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                    <UploadCloud className="size-5" />
                                </div>
                                <p className="mt-2 text-xs font-semibold text-foreground">
                                    Select new PDF file
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    PDF format up to 20 MB
                                </p>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/30 p-3">
                                <div className="flex items-center gap-3 overflow-hidden">
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <FileUp className="size-4" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="truncate text-xs font-medium text-foreground">
                                            {file.name}
                                        </p>
                                        <p className="text-[11px] text-muted-foreground">
                                            {formatBytes(file.size)}
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                    onClick={() => {
                                        setFile(null);

                                        if (fileInputRef.current) {
                                            fileInputRef.current.value = '';
                                        }
                                    }}
                                    disabled={isSubmitting}
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={isSubmitting || !file}
                        >
                            {isSubmitting ? (
                                <>
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                    Replacing...
                                </>
                            ) : (
                                'Replace & Reset Placements'
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
