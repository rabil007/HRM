import { CheckIcon, ChevronDownIcon, Loader2, PlusIcon } from 'lucide-react';
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

const NONE_SENTINEL = '__creatable_select_none__';

export type CreatableOption = {
    id: number | string;
    label: string;
    value: string;
    keywords?: string;
};

export type CreatableSelectVariant = 'dark' | 'card';

export type CreatableSelectProps = {
    value: string;
    onValueChange: (value: string) => void;
    onClose?: () => void;
    options: CreatableOption[];
    onOptionsChange?: (options: CreatableOption[]) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    variant?: CreatableSelectVariant;
    disabled?: boolean;
    size?: 'default' | 'sm';
    className?: string;
    creatable?: boolean;
    canCreate?: boolean;
    createConfig?: {
        submit: (query: string) => Promise<{ id: number | string; label: string }>;
    };
    emptyMessage?: string;
};

function hasExactLabelMatch(query: string, options: CreatableOption[]): boolean {
    const normalized = query.trim().toLowerCase();

    if (normalized === '') {
        return false;
    }

    return options.some((option) => option.label.trim().toLowerCase() === normalized);
}

export function CreatableSelect({
    value,
    onValueChange,
    onClose,
    options,
    onOptionsChange,
    variant = 'card',
    placeholder = '—',
    searchPlaceholder = 'Search...',
    disabled = false,
    size = 'default',
    className,
    creatable = false,
    canCreate = false,
    createConfig,
    emptyMessage = 'No results found.',
}: CreatableSelectProps): React.ReactElement {
    const [open, setOpen] = React.useState(false);
    const [searchQuery, setSearchQuery] = React.useState('');
    const [isCreating, setIsCreating] = React.useState(false);
    const [createError, setCreateError] = React.useState<string | null>(null);

    const radixValue = value === '' ? NONE_SENTINEL : value || NONE_SENTINEL;
    const selectedOption = options.find((option) => option.value === value);
    const displayLabel = selectedOption?.label || (value === '' ? '' : value);

    const showCreateRow =
        creatable &&
        canCreate &&
        Boolean(createConfig) &&
        searchQuery.trim() !== '' &&
        !hasExactLabelMatch(searchQuery, options);

    const handleOpenChange = (nextOpen: boolean): void => {
        setOpen(nextOpen);

        if (!nextOpen) {
            setSearchQuery('');
            setCreateError(null);
            onClose?.();
        }
    };

    const handleSelect = (itemValue: string): void => {
        onValueChange(itemValue === NONE_SENTINEL ? '' : itemValue);
        handleOpenChange(false);
    };

    const handleCreate = async (): Promise<void> => {
        if (!createConfig || isCreating) {
            return;
        }

        const query = searchQuery.trim();

        if (query === '') {
            return;
        }

        setIsCreating(true);
        setCreateError(null);

        try {
            const created = await createConfig.submit(query);
            const nextValue = String(created.id);
            const nextOption: CreatableOption = {
                id: created.id,
                label: created.label,
                value: nextValue,
            };

            if (!options.some((option) => option.value === nextValue)) {
                onOptionsChange?.([...options, nextOption]);
            }

            onValueChange(nextValue);
            setSearchQuery('');
            setCreateError(null);
        } catch (error) {
            setCreateError(error instanceof Error ? error.message : 'Could not create this option.');
        } finally {
            setIsCreating(false);
        }
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
                    data-slot="creatable-select-trigger"
                    className={triggerClassName}
                >
                    <span className={cn('line-clamp-1 text-left', !displayLabel && 'text-muted-foreground')}>
                        {displayLabel || placeholder}
                    </span>
                    <ChevronDownIcon className="size-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                data-slot="creatable-select-content"
                className="w-(--radix-popover-trigger-width) p-0"
                align="start"
            >
                <Command shouldFilter>
                    <CommandInput
                        placeholder={searchPlaceholder}
                        value={searchQuery}
                        onValueChange={setSearchQuery}
                    />
                    <CommandList>
                        <CommandEmpty>{emptyMessage}</CommandEmpty>
                        <CommandItem
                            value="__none__"
                            onSelect={() => {
                                handleSelect(NONE_SENTINEL);
                            }}
                        >
                            {placeholder}
                        </CommandItem>
                        {options.map((option) => (
                            <CommandItem
                                key={option.value}
                                value={[option.label, option.value, option.keywords]
                                    .filter(Boolean)
                                    .join(' ')}
                                onSelect={() => {
                                    handleSelect(option.value);
                                }}
                            >
                                <span className="flex-1 truncate">{option.label}</span>
                                {option.value === value ? (
                                    <CheckIcon className="size-4 shrink-0 opacity-70" />
                                ) : null}
                            </CommandItem>
                        ))}
                        {showCreateRow ? (
                            <CommandItem
                                value={`__create__ ${searchQuery}`}
                                disabled={isCreating}
                                onSelect={() => {
                                    void handleCreate();
                                }}
                                className="border-t border-border/60 text-primary"
                            >
                                {isCreating ? (
                                    <Loader2 className="size-4 shrink-0 animate-spin" />
                                ) : (
                                    <PlusIcon className="size-4 shrink-0" />
                                )}
                                <span className="flex-1 truncate">
                                    Create &quot;{searchQuery.trim()}&quot;
                                </span>
                            </CommandItem>
                        ) : null}
                    </CommandList>
                    {createError ? (
                        <p className="border-t border-border/60 px-3 py-2 text-xs text-destructive">
                            {createError}
                        </p>
                    ) : null}
                </Command>
            </PopoverContent>
        </Popover>
    );
}
