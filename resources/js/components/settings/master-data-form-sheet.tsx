import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

export const masterDataFieldLabelClass =
    'text-xs font-semibold uppercase tracking-wider text-muted-foreground/70';

export const masterDataInputClass =
    'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

type MasterDataFormSheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    children: ReactNode;
    footer?: ReactNode;
    contentClassName?: string;
};

export function MasterDataFormSheet({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
    contentClassName,
}: MasterDataFormSheetProps) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className={cn(
                    'flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md',
                    contentClassName,
                )}
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {title}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {description}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-5 overflow-y-auto p-8">
                    {children}
                </div>

                {footer}
            </SheetContent>
        </Sheet>
    );
}

type MasterDataFormSheetFooterProps = {
    onCancel: () => void;
    onSubmit?: () => void;
    processing?: boolean;
    submitLabel?: string;
    cancelLabel?: string;
    leading?: ReactNode;
    showSubmit?: boolean;
};

export function MasterDataFormSheetFooter({
    onCancel,
    onSubmit,
    processing = false,
    submitLabel = 'Save',
    cancelLabel = 'Cancel',
    leading,
    showSubmit = true,
}: MasterDataFormSheetFooterProps) {
    const actions = (
        <div
            className={cn(
                'flex gap-3',
                leading ? 'w-full sm:w-auto' : 'w-full',
            )}
        >
            <Button
                type="button"
                variant="ghost"
                className={cn(
                    'h-11 rounded-xl px-6 text-muted-foreground',
                    leading
                        ? 'flex-1 sm:min-w-[7.5rem] sm:flex-none'
                        : 'flex-1',
                )}
                onClick={onCancel}
            >
                {cancelLabel}
            </Button>
            {showSubmit && onSubmit ? (
                <Button
                    type="button"
                    className={cn(
                        'h-11 rounded-xl px-6 font-semibold',
                        leading
                            ? 'flex-1 sm:min-w-[7.5rem] sm:flex-none'
                            : 'flex-1',
                    )}
                    disabled={processing}
                    onClick={onSubmit}
                >
                    {processing ? 'Saving…' : submitLabel}
                </Button>
            ) : null}
        </div>
    );

    if (!leading) {
        return (
            <div className="border-t border-border/60 bg-background/40 p-6">
                {actions}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 border-t border-border/60 bg-background/40 p-6 sm:flex-row sm:items-center sm:justify-between">
            <div className="w-full sm:w-auto">{leading}</div>
            {actions}
        </div>
    );
}

type MasterDataFieldProps = {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
};

export function MasterDataField({
    id,
    label,
    error,
    children,
    className,
}: MasterDataFieldProps) {
    return (
        <div className={cn('space-y-2', className)}>
            <Label htmlFor={id} className={masterDataFieldLabelClass}>
                {label}
            </Label>
            {children}
            {error ? (
                <div className="text-xs font-medium text-destructive">
                    {error}
                </div>
            ) : null}
        </div>
    );
}

type MasterDataActiveToggleProps = {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    title?: string;
    description?: string;
};

export function MasterDataActiveToggle({
    checked,
    onCheckedChange,
    title = 'Active',
    description = 'Disable to hide from selections.',
}: MasterDataActiveToggleProps) {
    return (
        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
            <div className="min-w-0">
                <div className="text-sm font-semibold text-foreground">
                    {title}
                </div>
                <div className="text-xs text-muted-foreground/80">
                    {description}
                </div>
            </div>
            <Switch checked={checked} onCheckedChange={onCheckedChange} />
        </div>
    );
}
