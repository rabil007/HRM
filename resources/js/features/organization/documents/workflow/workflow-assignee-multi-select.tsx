import { useMemo, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { WorkflowAssigneeOption } from '@/features/organization/documents/workflow/types';
import { cn } from '@/lib/utils';

export function WorkflowAssigneeMultiSelect({
    label,
    options,
    value,
    onChange,
    error,
}: {
    label: string;
    options: WorkflowAssigneeOption[];
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const selected = useMemo(() => new Set(value), [value]);

    const filtered = useMemo(() => {
        const term = query.trim().toLowerCase();

        if (term === '') {
            return options;
        }

        return options.filter(
            (option) =>
                option.name.toLowerCase().includes(term) ||
                (option.email ?? '').toLowerCase().includes(term),
        );
    }, [options, query]);

    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Search users"
            />
            <div className="max-h-40 space-y-2 overflow-y-auto rounded-lg border border-border/70 p-3">
                {filtered.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No users found.
                    </p>
                ) : (
                    filtered.map((option) => (
                        <label
                            key={option.id}
                            className={cn(
                                'flex cursor-pointer items-start gap-2 rounded-md p-2 hover:bg-muted/40',
                            )}
                        >
                            <Checkbox
                                checked={selected.has(option.id)}
                                onCheckedChange={() => {
                                    if (selected.has(option.id)) {
                                        onChange(
                                            value.filter(
                                                (id) => id !== option.id,
                                            ),
                                        );
                                    } else {
                                        onChange([...value, option.id]);
                                    }
                                }}
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    {option.name}
                                </span>
                                {option.email ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {option.email}
                                    </span>
                                ) : null}
                            </span>
                        </label>
                    ))
                )}
            </div>
            {error ? <p className="text-xs text-destructive">{error}</p> : null}
        </div>
    );
}
