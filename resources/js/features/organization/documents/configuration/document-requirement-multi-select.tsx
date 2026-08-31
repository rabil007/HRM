import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

    const optionMap = useMemo(() => {
        const map = new Map<number, DocumentRequirementOption>();

        for (const opt of options) {
            map.set(opt.id, opt);
        }

        return map;
    }, [options]);

    const selectedOptions = useMemo(() => {
        return value
            .map((idVal) => optionMap.get(idVal))
            .filter(
                (opt): opt is DocumentRequirementOption => opt !== undefined,
            );
    }, [value, optionMap]);

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

    const removeOne = (optionId: number): void => {
        if (disabled) {
            return;
        }

        onChange(value.filter((idValue) => idValue !== optionId));
    };

    const clearAll = (): void => {
        if (disabled) {
            return;
        }

        onChange([]);
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
            <div className="flex items-center justify-between">
                <Label
                    htmlFor={id}
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    {label}
                </Label>
                {value.length > 0 && !disabled ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-auto p-0 text-[11px] text-muted-foreground hover:text-foreground"
                        onClick={clearAll}
                    >
                        Clear all ({value.length})
                    </Button>
                ) : null}
            </div>

            {selectedOptions.length > 0 ? (
                <div className="flex flex-wrap gap-1.5 pb-1">
                    {selectedOptions.map((opt) => (
                        <Badge
                            key={opt.id}
                            variant="secondary"
                            className="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-normal"
                        >
                            <span className="max-w-[200px] truncate">
                                {opt.label}
                            </span>
                            {!disabled ? (
                                <button
                                    type="button"
                                    onClick={() => removeOne(opt.id)}
                                    className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20 focus:outline-none"
                                    aria-label={`Remove ${opt.label}`}
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            ) : null}
                        </Badge>
                    ))}
                </div>
            ) : null}

            {options.length > 6 ? (
                <Input
                    id={id}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={`Search ${label.toLowerCase()}…`}
                    className="h-9 rounded-xl border-border bg-card text-xs"
                    disabled={disabled}
                />
            ) : null}
            <div
                className={cn(
                    'max-h-36 space-y-0.5 overflow-y-auto rounded-xl border border-border bg-card p-1.5 text-xs',
                    disabled && 'opacity-60',
                )}
            >
                {filtered.length === 0 ? (
                    <p className="px-2 py-3 text-xs text-muted-foreground">
                        No {label.toLowerCase()} found.
                    </p>
                ) : (
                    <>
                        <label className="flex cursor-pointer items-center gap-2 rounded-lg border-b border-border/60 px-2 py-1.5 text-xs font-medium hover:bg-muted/50">
                            <Checkbox
                                checked={selectAllState}
                                disabled={disabled}
                                onCheckedChange={toggleVisible}
                                aria-label={selectAllLabel}
                            />
                            <span>Select all</span>
                        </label>
                        {filtered.map((option) => {
                            const checked = selected.has(option.id);

                            return (
                                <label
                                    key={option.id}
                                    className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-xs hover:bg-muted/50"
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
            {error ? (
                <div className="text-xs text-destructive">{error}</div>
            ) : null}
        </div>
    );
}
