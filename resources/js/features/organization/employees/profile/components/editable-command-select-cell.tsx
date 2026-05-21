import type { ReactElement } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { Loader2, PlusIcon } from 'lucide-react';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import type { CreatableMasterDataContext, CreatableMasterDataKey } from '@/lib/master-data/creatable-registry';

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
};

function hasExactLabelMatch(query: string, items: CommandSelectOption[]): boolean {
    const normalized = query.trim().toLowerCase();

    if (normalized === '') {
        return false;
    }

    return items.some((item) => item.label.trim().toLowerCase() === normalized);
}

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

    const showCreateRow = useMemo(
        () =>
            creatable &&
            canCreate &&
            searchQuery.trim() !== '' &&
            !hasExactLabelMatch(searchQuery, localItems),
        [canCreate, creatable, localItems, searchQuery],
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

            const nextItems = localItems.some((item) => item.value === nextItem.value)
                ? localItems
                : [...localItems, nextItem];

            setLocalItems(nextItems);
            onItemsChange?.(nextItems);
            onSelect(nextItem.value);
            setSearchQuery('');
            setActiveField(null);
        } catch (error) {
            setCreateError(error instanceof Error ? error.message : 'Could not create this option.');
        } finally {
            setIsCreating(false);
        }
    };

    return (
        <div className="group flex min-w-0 flex-col gap-1 rounded-xl border border-white/[0.07] bg-white/[0.03] px-3 py-2.5 transition-colors hover:border-white/[0.12] hover:bg-white/[0.06]">
            <div className="text-[10px] font-semibold uppercase tracking-wider text-zinc-600">
                {label}
            </div>
            <button
                type="button"
                className="min-w-0 truncate text-left text-xs font-semibold text-zinc-300 hover:text-white disabled:cursor-default disabled:hover:text-zinc-300"
                onClick={() => beginEdit(field)}
                disabled={!canEdit}
            >
                {currentLabel || '—'}
            </button>

            <CommandDialog
                open={activeField === field && canEdit}
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
                    <CommandEmpty>No results found.</CommandEmpty>
                    <CommandItem
                        value="__none__"
                        onSelect={() => {
                            onSelect('');
                            setActiveField(null);
                        }}
                    >
                        —
                    </CommandItem>
                    {localItems.map((row) => (
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
            </CommandDialog>
        </div>
    );
}
