import { FileText, Layers } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSelectContent: () => void;
    onSelectPdf: () => void;
};

export function TemplateCreateChoiceDialog({
    open,
    onOpenChange,
    onSelectContent,
    onSelectPdf,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Create Document Template</DialogTitle>
                    <DialogDescription>
                        Choose how you would like to design this document
                        template.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3 py-2">
                    <button
                        type="button"
                        onClick={() => {
                            onOpenChange(false);
                            onSelectContent();
                        }}
                        className="flex items-start gap-4 rounded-xl border border-border/80 p-4 text-left transition-colors hover:border-primary/50 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                            <FileText className="size-5" />
                        </div>
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-foreground">
                                Content Template
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Create a text or HTML-based template with
                                dynamic merge fields like employee name,
                                designation, and company details.
                            </p>
                        </div>
                    </button>

                    <button
                        type="button"
                        onClick={() => {
                            onOpenChange(false);
                            onSelectPdf();
                        }}
                        className="flex items-start gap-4 rounded-xl border border-border/80 p-4 text-left transition-colors hover:border-primary/50 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    >
                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
                            <Layers className="size-5" />
                        </div>
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-foreground">
                                Upload Existing PDF
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Upload an official branded company PDF and
                                visually place employee merge fields across any
                                page with drag-and-drop.
                            </p>
                        </div>
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
