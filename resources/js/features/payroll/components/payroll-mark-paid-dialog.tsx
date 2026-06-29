import { FileCheck, Upload, X } from 'lucide-react';
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
    onConfirm: (file: File | null) => void;
    processing: boolean;
}) {
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] ?? null;
        setSelectedFile(file);
    };

    const handleRemoveFile = () => {
        setSelectedFile(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleClose = (newOpen: boolean) => {
        if (!newOpen) {
            setSelectedFile(null);
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
                        This confirms payment has been completed for all payroll records in this period.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2 py-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Payment Proof / Evidence (Optional)
                    </Label>

                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
                        className="hidden"
                        onChange={handleFileChange}
                    />

                    {!selectedFile ? (
                        <div
                            onClick={() => fileInputRef.current?.click()}
                            className="flex flex-col items-center justify-center cursor-pointer rounded-xl border border-dashed border-muted-foreground/30 bg-muted/20 p-4 transition-colors hover:border-primary/50 hover:bg-accent/30 text-center gap-2"
                        >
                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Upload className="h-4 w-4" />
                            </div>
                            <div>
                                <p className="text-xs font-medium">Click to upload payment proof document</p>
                                <p className="text-[11px] text-muted-foreground">PDF, JPG, PNG or DOC (Max 10MB)</p>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
                            <div className="flex items-center gap-2.5 min-w-0">
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-600 dark:text-emerald-400">
                                    <FileCheck className="h-4 w-4" />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold truncate">{selectedFile.name}</p>
                                    <p className="text-[10px] text-muted-foreground">
                                        {(selectedFile.size / 1024 / 1024).toFixed(2)} MB
                                    </p>
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7 shrink-0 rounded-lg text-muted-foreground hover:text-destructive"
                                onClick={handleRemoveFile}
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        </div>
                    )}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={processing}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm(selectedFile);
                        }}
                    >
                        {processing ? 'Saving…' : 'Mark as paid'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
