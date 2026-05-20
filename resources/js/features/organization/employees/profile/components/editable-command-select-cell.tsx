import type { ReactElement } from 'react';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';

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
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onSelect: (value: string) => void;
};

export function EditableCommandSelectCell({
    field,
    label,
    currentLabel,
    title,
    description,
    items,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onSelect,
}: EditableCommandSelectCellProps): ReactElement {
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
                        setActiveField(null);
                    }
                }}
                title={title}
                description={description}
            >
                <CommandInput placeholder={description} />
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
                    {items.map((row) => (
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
            </CommandDialog>
        </div>
    );
}
