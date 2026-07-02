import type { ReactElement } from 'react';
import { useEffect, useMemo, useState } from 'react';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { CommandCreateRow } from '@/components/ui/command-create-row';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { filterCreatableOptions } from '@/lib/filter-creatable-options';
import type {
    CreatableMasterDataContext,
    CreatableMasterDataKey,
} from '@/lib/master-data/creatable-registry';
import { cn } from '@/lib/utils';
import { employeeFieldMissingHighlightClass } from '@/pages/organization/_lib/employee-required-field-labels';

export type CommandSelectOption = {
    id: number;
    label: string;
    value: string;
    extra?: string | null;
    search?: string;
};

export type EditableCommandSelectCellProps = {
    field: string;
    label: string;
    currentLabel: string;
    title: string;
    description: string;
    items: CommandSelectOption[];
    onItemsChange?: (items: CommandSelectOption[]) => void;
    creatableKey?: CreatableMasterDataKey;
    creatableContext?: CreatableMasterDataContext;
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onSelect: (value: string) => void;
    highlightMissing?: boolean;
};

export function EditableCommandSelectCell({
    field,
    label,
    currentLabel,
    title,
    description,
    items,
    onItemsChange,
    creatableKey,
    creatableContext,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onSelect,
    highlightMissing = false,
}: EditableCommandSelectCellProps): ReactElement {
    const [searchQuery, setSearchQuery] = useState('');
    const [localItems, setLocalItems] = useState(items);
    const [isCreating, setIsCreating] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);

    const creatable = Boolean(creatableKey);
    const { canCreate, createConfig } = useCreatableMasterData(
        creatableKey ?? 'department',
        creatableContext,
    );

    useEffect(() => {
        setLocalItems(items);
    }, [items]);

    const filteredItems = useMemo(
        () => filterCreatableOptions(localItems, searchQuery),
        [localItems, searchQuery],
    );

    const isSearching = searchQuery.trim() !== '';

    const showCreateRow = useMemo(
        () =>
            creatable &&
            canCreate &&
            Boolean(createConfig) &&
            isSearching &&
            filteredItems.length === 0,
        [canCreate, creatable, createConfig, filteredItems.length, isSearching],
    );

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
            const nextItem: CommandSelectOption = {
                id: Number(created.id),
                label: created.label,
                value: String(created.id),
            };

            const nextItems = localItems.some(
                (item) => item.value === nextItem.value,
            )
                ? localItems
                : [...localItems, nextItem];

            setLocalItems(nextItems);
            onItemsChange?.(nextItems);
            onSelect(nextItem.value);
            setSearchQuery('');
            setActiveField(null);
        } catch (error) {
            setCreateError(
                error instanceof Error
                    ? error.message
                    : 'Could not create this option.',
            );
        } finally {
            setIsCreating(false);
        }
    };

    return (
        <div
            data-employee-field={field}
            className={cn(
                'group flex min-w-0 flex-col gap-1 rounded-xl border border-border/80 bg-muted/40 px-3 py-2.5 transition-colors hover:border-border hover:bg-muted/80 dark:border-white/[0.07] dark:bg-white/[0.03] dark:hover:border-white/[0.12] dark:hover:bg-white/[0.06]',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
        >
            <div
                className={cn(
                    'text-[10px] font-semibold tracking-wider text-muted-foreground uppercase',
                    highlightMissing && 'text-rose-400',
                )}
            >
                {label}
            </div>
            <button
                type="button"
                className="min-w-0 truncate text-left text-xs font-semibold text-foreground hover:text-primary disabled:cursor-default disabled:hover:text-foreground dark:text-zinc-300 dark:hover:text-white dark:disabled:hover:text-zinc-300"
                onClick={() => beginEdit(field)}
                disabled={!canEdit}
            >
                {currentLabel || '—'}
            </button>

            <CommandDialog
                open={activeField === field && canEdit}
                shouldFilter={false}
                onOpenChange={(open) => {
                    if (!open) {
                        setSearchQuery('');
                        setCreateError(null);
                        setActiveField(null);
                    }
                }}
                title={title}
                description={description}
            >
                <CommandInput
                    placeholder={description}
                    value={searchQuery}
                    onValueChange={setSearchQuery}
                />
                <CommandList>
                    {!showCreateRow &&
                    filteredItems.length === 0 &&
                    isSearching ? (
                        <CommandEmpty>No results found.</CommandEmpty>
                    ) : null}
                    {!isSearching ? (
                        <CommandItem
                            value="__none__"
                            onSelect={() => {
                                onSelect('');
                                setActiveField(null);
                            }}
                        >
                            —
                        </CommandItem>
                    ) : null}
                    {filteredItems.map((row) => (
                        <CommandItem
                            key={row.id}
                            value={row.search ?? row.label}
                            onSelect={() => {
                                onSelect(row.value);
                                setActiveField(null);
                            }}
                        >
                            {row.label}
                            {row.extra ? (
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {row.extra}
                                </span>
                            ) : null}
                        </CommandItem>
                    ))}
                </CommandList>
                {showCreateRow ? (
                    <CommandCreateRow
                        query={searchQuery}
                        isCreating={isCreating}
                        onCreate={handleCreate}
                    />
                ) : null}
                {createError ? (
                    <p className="border-t border-border/60 px-3 py-2 text-xs text-destructive">
                        {createError}
                    </p>
                ) : null}
            </CommandDialog>
        </div>
    );
}
