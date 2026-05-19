import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import * as React from 'react';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const NONE_SENTINEL = '__app_select_none__';

export type AppSelectVariant = 'dark' | 'card';

export type AppSelectProps = {
    value: string;
    onValueChange: (value: string) => void;
    onClose?: () => void;
    variant?: AppSelectVariant;
    placeholder?: string;
    searchPlaceholder?: string;
    disabled?: boolean;
    size?: 'default' | 'sm';
    className?: string;
    children: React.ReactNode;
};

export type AppSelectItemProps = {
    value: string;
    children: React.ReactNode;
    disabled?: boolean;
    className?: string;
    /** Extra terms used when filtering (e.g. country codes). */
    keywords?: string;
};

type ParsedAppSelectItem = {
    value: string;
    radixValue: string;
    label: string;
    searchValue: string;
    disabled?: boolean;
    className?: string;
};

function getNodeText(node: React.ReactNode): string {
    if (node === null || node === undefined || typeof node === 'boolean') {
        return '';
    }

    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }

    if (Array.isArray(node)) {
        return node.map(getNodeText).join('');
    }

    if (React.isValidElement<{ children?: React.ReactNode }>(node)) {
        return getNodeText(node.props.children);
    }

    return '';
}

function parseAppSelectItems(children: React.ReactNode): ParsedAppSelectItem[] {
    const items: ParsedAppSelectItem[] = [];

    React.Children.forEach(children, (child) => {
        if (!React.isValidElement<AppSelectItemProps>(child) || child.type !== AppSelectItem) {
            return;
        }

        const { value, disabled, className, keywords } = child.props;
        const label = getNodeText(child.props.children).trim();
        const radixValue = value === '' ? NONE_SENTINEL : value;

        items.push({
            value,
            radixValue,
            label,
            searchValue: [label, value, keywords].filter(Boolean).join(' '),
            disabled,
            className,
        });
    });

    return items;
}

/** Marker component — options are rendered by `AppSelect` with built-in search. */
export function AppSelectItem(_props: AppSelectItemProps): null {
    return null;
}

export function AppSelect({
    value,
    onValueChange,
    onClose,
    variant = 'card',
    placeholder = '—',
    searchPlaceholder = 'Search...',
    disabled = false,
    size = 'default',
    className,
    children,
}: AppSelectProps): React.ReactElement {
    const [open, setOpen] = React.useState(false);
    const items = React.useMemo(() => parseAppSelectItems(children), [children]);

    const radixValue = value === '' ? NONE_SENTINEL : value || NONE_SENTINEL;
    const selectedItem = items.find((item) => item.radixValue === radixValue);
    const displayLabel = selectedItem?.label || (value === '' ? '' : value);

    const handleOpenChange = (nextOpen: boolean): void => {
        setOpen(nextOpen);

        if (!nextOpen) {
            onClose?.();
        }
    };

    const handleSelect = (itemValue: string): void => {
        onValueChange(itemValue === NONE_SENTINEL ? '' : itemValue);
        handleOpenChange(false);
    };

    const triggerClassName = cn(
        'flex w-full items-center justify-between gap-2 rounded-md border text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50',
        variant === 'dark'
            ? 'border-white/10 bg-white/5 text-zinc-100 focus-visible:ring-primary/40'
            : 'border-border bg-card text-foreground focus-visible:ring-primary/40',
        variant === 'dark' ? 'h-11 rounded-xl' : 'h-11 rounded-xl',
        size === 'sm' && 'h-8 rounded-lg px-2 text-xs',
        size === 'default' && 'px-3 py-2',
        className,
    );

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    data-slot="app-select-trigger"
                    className={triggerClassName}
                >
                    <span className={cn('line-clamp-1 text-left', !displayLabel && 'text-muted-foreground')}>
                        {displayLabel || placeholder}
                    </span>
                    <ChevronDownIcon className="size-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                data-slot="app-select-content"
                className="w-(--radix-popover-trigger-width) p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>No results found.</CommandEmpty>
                        {items.map((item) => (
                            <CommandItem
                                key={item.radixValue}
                                value={item.searchValue}
                                disabled={item.disabled}
                                className={item.className}
                                onSelect={() => {
                                    if (!item.disabled) {
                                        handleSelect(item.radixValue);
                                    }
                                }}
                            >
                                <span className="flex-1 truncate">{item.label || item.value}</span>
                                {item.radixValue === radixValue ? (
                                    <CheckIcon className="size-4 shrink-0 opacity-70" />
                                ) : null}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
