import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    addSelectedIds,
    headerCheckboxState,
    isAllVisibleSelected,
    isSomeVisibleSelected,
    removeSelectedIds,
} from '@/lib/record-selection';
import { cn } from '@/lib/utils';

export type DocumentRequirementOption = {
    id: number;
    label: string;
};

export function DocumentRequirementMultiSelect({
    id,
    label,
    options,
    value,
    onChange,
    error,
    disabled = false,
}: {
    id: string;
    label: string;
    options: DocumentRequirementOption[];
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
    disabled?: boolean;
}): ReactElement {
    const [query, setQuery] = useState('');
    const selected = useMemo(() => new Set(value), [value]);

    const filtered = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (term === '') {
            return options;
        }

        return options.filter((option) =>
            option.label.toLowerCase().includes(term),
        );
    }, [options, query]);

    const visibleIds = useMemo(
        () => filtered.map((option) => option.id),
        [filtered],
    );
    const allVisibleSelected = isAllVisibleSelected(selected, visibleIds);
    const someVisibleSelected = isSomeVisibleSelected(selected, visibleIds);
    const selectAllState = headerCheckboxState(
        allVisibleSelected,
        someVisibleSelected,
    );

    const toggle = (optionId: number): void => {
        if (disabled) {
            return;
        }

        if (selected.has(optionId)) {
            onChange(value.filter((idValue) => idValue !== optionId));

            return;
        }

        onChange([...value, optionId]);
    };

    const toggleVisible = (): void => {
        if (disabled || visibleIds.length === 0) {
            return;
        }

        if (allVisibleSelected) {
            onChange([...removeSelectedIds(selected, visibleIds)]);

            return;
        }

        onChange([...addSelectedIds(selected, visibleIds)]);
    };

    const selectAllLabel =
        query.trim() === ''
            ? `Select all ${label.toLowerCase()}`
            : `Select all matching ${label.toLowerCase()}`;

    return (
        <div className="space-y-2">
            <Label
                htmlFor={id}
                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
            >
                {label}
            </Label>
            {options.length > 6 ? (
                <Input
                    id={id}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={`Search ${label.toLowerCase()}…`}
                    className="h-10 rounded-xl border-border bg-card"
                    disabled={disabled}
                />
            ) : null}
            <div
                className={cn(
                    'max-h-40 space-y-1 overflow-y-auto rounded-xl border border-border bg-card p-2',
                    disabled && 'opacity-60',
                )}
            >
                {filtered.length === 0 ? (
                    <p className="px-2 py-3 text-xs text-muted-foreground">
                        No {label.toLowerCase()} found.
                    </p>
                ) : (
                    <>
                        <label className="flex cursor-pointer items-center gap-2 rounded-lg border-b border-border/60 px-2 py-1.5 text-sm hover:bg-muted/50">
                            <Checkbox
                                checked={selectAllState}
                                disabled={disabled}
                                onCheckedChange={toggleVisible}
                                aria-label={selectAllLabel}
                            />
                            <span className="font-medium">Select all</span>
                        </label>
                        {filtered.map((option) => {
                            const checked = selected.has(option.id);

                            return (
                                <label
                                    key={option.id}
                                    className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-muted/50"
                                >
                                    <Checkbox
                                        checked={checked}
                                        disabled={disabled}
                                        onCheckedChange={() =>
                                            toggle(option.id)
                                        }
                                        aria-label={option.label}
                                    />
                                    <span className="min-w-0 truncate">
                                        {option.label}
                                    </span>
                                </label>
                            );
                        })}
                    </>
                )}
            </div>
            {value.length > 0 ? (
                <p className="text-xs text-muted-foreground">
                    {value.length} selected
                </p>
            ) : null}
            {error ? (
                <div className="text-xs text-destructive">{error}</div>
            ) : null}
        </div>
    );
}
