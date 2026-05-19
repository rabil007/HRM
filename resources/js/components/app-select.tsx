import * as React from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

const NONE_SENTINEL = '__app_select_none__';

export type AppSelectVariant = 'dark' | 'card';

export type AppSelectProps = {
    value: string;
    onValueChange: (value: string) => void;
    onClose?: () => void;
    variant?: AppSelectVariant;
    placeholder?: string;
    disabled?: boolean;
    size?: 'default' | 'sm';
    className?: string;
    children: React.ReactNode;
};

export function AppSelect({
    value,
    onValueChange,
    onClose,
    variant = 'card',
    placeholder = '—',
    disabled = false,
    size = 'default',
    className,
    children,
}: AppSelectProps): React.ReactElement {
    const radixValue = value === '' ? NONE_SENTINEL : value || NONE_SENTINEL;

    const handleValueChange = (v: string): void => {
        onValueChange(v === NONE_SENTINEL ? '' : v);
    };

    const handleOpenChange = (open: boolean): void => {
        if (!open) {
            onClose?.();
        }
    };

    return (
        <Select value={radixValue} onValueChange={handleValueChange} onOpenChange={handleOpenChange} disabled={disabled}>
            <SelectTrigger
                size={size}
                className={cn(
                    variant === 'dark'
                        ? 'border-white/10 bg-white/5 text-zinc-100 placeholder:text-zinc-500 focus-visible:ring-primary/40 data-placeholder:text-zinc-500'
                        : 'border-border bg-card text-foreground focus-visible:ring-primary/40',
                    variant === 'dark' ? 'h-11 rounded-xl' : 'h-11 rounded-xl',
                    size === 'sm' && 'h-8 rounded-lg',
                    className,
                )}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>{children}</SelectContent>
        </Select>
    );
}

export type AppSelectItemProps = {
    value: string;
    children: React.ReactNode;
    disabled?: boolean;
    className?: string;
};

export function AppSelectItem({ value, children, disabled, className }: AppSelectItemProps): React.ReactElement {
    const radixValue = value === '' ? NONE_SENTINEL : value;

    return (
        <SelectItem value={radixValue} disabled={disabled} className={className}>
            {children}
        </SelectItem>
    );
}
