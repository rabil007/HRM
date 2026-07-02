import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

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
                className="flex w-full flex-col gap-0 rounded-none glass-card border-border/60 p-0 sm:max-w-md"
            >
                <SheetHeader className="flex-shrink-0 border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {title}
                    </SheetTitle>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    {children}
                </div>

                <div className="flex flex-shrink-0 gap-3 border-t border-border/60 p-8 pt-6">
                    <Button
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={onReset}
                    >
                        {resetText}
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        onClick={() => onOpenChange(false)}
                    >
                        {applyText}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
