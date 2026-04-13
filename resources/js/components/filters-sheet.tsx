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
            <SheetContent side="right" className="w-full sm:max-w-md border-white/5 bg-black/60 backdrop-blur-3xl p-0">
                <SheetHeader className="p-8 pb-6 border-b border-white/5">
                    <SheetTitle className="text-xl font-bold tracking-tight text-white">{title}</SheetTitle>
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

