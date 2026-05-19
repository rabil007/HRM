import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';

export function FiltersSheet({
    open,
    onOpenChange,
    title = 'Filters',
    children,
    onReset,
    applyText = 'Apply',
    resetText = 'Reset',
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: string;
    children: ReactNode;
    onReset: () => void;
    applyText?: string;
    resetText?: string;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none border-border/60 p-0 glass-card sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">{title}</SheetTitle>
                </SheetHeader>

                <div className="p-8 space-y-6">
                    {children}

                    <div className="flex gap-3 pt-2">
                        <Button
                            variant="ghost"
                            className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                            onClick={onReset}
                        >
                            {resetText}
                        </Button>
                        <Button className="rounded-xl h-11 px-6 flex-1 font-semibold" onClick={() => onOpenChange(false)}>
                            {applyText}
                        </Button>
                    </div>
                </div>
            </SheetContent>
        </Sheet>
    );
}

