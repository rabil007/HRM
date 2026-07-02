import { FileCheck, Plus, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export function PayrollMarkPaidDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (files: File[]) => void;
    processing: boolean;
}) {
    const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (!e.target.files || e.target.files.length === 0) {
            return;
        }

        const newFiles = Array.from(e.target.files);
        setSelectedFiles((prev) => {
            const existingKeys = new Set(
                prev.map((f) => `${f.name}-${f.size}`),
            );
            const filtered = newFiles.filter(
                (f) => !existingKeys.has(`${f.name}-${f.size}`),
            );

            return [...prev, ...filtered];
        });

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleRemoveFile = (index: number) => {
        setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
    };

    const handleClose = (newOpen: boolean) => {
        if (!newOpen) {
            setSelectedFiles([]);

            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }

        onOpenChange(newOpen);
    };

    return (
        <AlertDialog open={open} onOpenChange={handleClose}>
            <AlertDialogContent className="glass-card sm:max-w-md">
                <AlertDialogHeader>
                    <AlertDialogTitle>Mark pay run as paid?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This confirms payment has been completed for all payroll
                        records in this period.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-3 py-2">
                    <div className="flex items-center justify-between">
                        <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            Payment Proof Documents ({selectedFiles.length})
                        </Label>
                        {selectedFiles.length > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs text-primary hover:text-primary"
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Add more
                            </Button>
                        ) : null}
                    </div>

                    <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
                        className="hidden"
                        onChange={handleFileChange}
                    />

                    {selectedFiles.length === 0 ? (
                        <div
                            onClick={() => fileInputRef.current?.click()}
                            className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-muted-foreground/30 bg-muted/20 p-4 text-center transition-colors hover:border-primary/50 hover:bg-accent/30"
                        >
                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Upload className="h-4 w-4" />
                            </div>
                            <div>
                                <p className="text-xs font-medium">
                                    Click to upload payment proof files
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    PDF, JPG, PNG or DOC (Upload multiple files)
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="max-h-48 space-y-2 overflow-y-auto pr-1">
                            {selectedFiles.map((file, idx) => (
                                <div
                                    key={`${file.name}-${idx}`}
                                    className="flex items-center justify-between gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-2.5"
                                >
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                                            <FileCheck className="h-3.5 w-3.5" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-semibold">
                                                {file.name}
                                            </p>
                                            <p className="text-[10px] text-muted-foreground">
                                                {(
                                                    file.size /
                                                    1024 /
                                                    1024
                                                ).toFixed(2)}{' '}
                                                MB
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="h-6 w-6 shrink-0 rounded-lg text-muted-foreground hover:text-destructive"
                                        onClick={() => handleRemoveFile(idx)}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={processing}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm(selectedFiles);
                        }}
                    >
                        {processing ? 'Saving…' : 'Mark as paid'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
