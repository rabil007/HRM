import { AlertTriangle, FileText, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export function TemplatePreviewDialog({
    open,
    onOpenChange,
    title,
    contentHtml,
    unresolvedPlaceholders = [],
    isLoading = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    contentHtml: string;
    unresolvedPlaceholders?: string[];
    isLoading?: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col overflow-hidden p-0 sm:max-w-2xl">
                <DialogHeader className="border-b border-border/60 px-6 pt-6 pb-4">
                    <div className="flex items-center gap-2">
                        <FileText className="h-5 w-5 text-primary" />
                        <DialogTitle className="text-lg font-semibold">
                            {title || 'Document Preview'}
                        </DialogTitle>
                    </div>
                    <DialogDescription>
                        Previewing with sample organization and employee data.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex-1 space-y-4 overflow-y-auto px-6 py-6">
                    {isLoading ? (
                        <div className="flex flex-col items-center justify-center py-16 text-muted-foreground">
                            <Loader2 className="h-8 w-8 animate-spin text-primary" />
                            <p className="mt-3 text-sm">Rendering preview...</p>
                        </div>
                    ) : (
                        <>
                            {unresolvedPlaceholders.length > 0 && (
                                <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-900 dark:text-amber-200">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                    <div>
                                        <p className="font-medium">
                                            Unresolved placeholders detected:
                                        </p>
                                        <p className="mt-0.5 opacity-90">
                                            {unresolvedPlaceholders.join(', ')}
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="min-h-[360px] rounded-xl border border-border/80 bg-background/80 p-8 font-sans text-sm leading-relaxed whitespace-pre-wrap text-foreground shadow-xs">
                                <div
                                    dangerouslySetInnerHTML={{
                                        __html: contentHtml,
                                    }}
                                />
                            </div>
                        </>
                    )}
                </div>

                <DialogFooter className="border-t border-border/60 bg-muted/20 px-6 py-4">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close Preview
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
