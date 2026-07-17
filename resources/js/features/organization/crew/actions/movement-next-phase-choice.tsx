import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { MovementNextPhaseOption } from './movement-action-config';

export function MovementNextPhaseChoice({
    id,
    label,
    value,
    options,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    options: MovementNextPhaseOption[];
    onChange: (value: string) => void;
    error?: string;
}): ReactElement {
    const labelId = `${id}-label`;

    return (
        <div className="space-y-2">
            <Label id={labelId} htmlFor={id}>
                {label} <span className="text-destructive">*</span>
            </Label>
            <RadioGroup
                id={id}
                value={value}
                onValueChange={onChange}
                aria-labelledby={labelId}
                aria-required="true"
                className="grid gap-2"
            >
                {options.map((option) => {
                    const selected = value === option.value;

                    return (
                        <RadioItem
                            key={option.value}
                            value={option.value}
                            className={cn(
                                'rounded-lg border bg-background p-3 text-left transition-colors outline-none',
                                'hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/40',
                                selected
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border/80',
                            )}
                            aria-describedby={`${id}-${option.value}-desc`}
                        >
                            <div className="text-sm font-medium text-foreground">
                                {option.label}
                            </div>
                            <p
                                id={`${id}-${option.value}-desc`}
                                className="mt-1 text-xs text-muted-foreground"
                            >
                                {option.description}
                            </p>
                        </RadioItem>
                    );
                })}
            </RadioGroup>
            <InputError message={error} />
        </div>
    );
}
