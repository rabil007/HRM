import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import { cn } from '@/lib/utils';
import type { TemplateAutomationMode } from '../types';

type Props = {
    name: string;
    value: TemplateAutomationMode;
    disabled: boolean;
    noneTitle: string;
    presetTitle: string;
    presetDescription?: string | null;
    highlighted?: boolean;
    onChange: (mode: Exclude<TemplateAutomationMode, null>) => void;
};

export function TemplateWorkflowDecisionCards({
    name,
    value,
    disabled,
    noneTitle,
    presetTitle,
    presetDescription,
    highlighted = false,
    onChange,
}: Props) {
    return (
        <RadioGroup
            value={value ?? ''}
            onValueChange={(next) =>
                onChange(next as Exclude<TemplateAutomationMode, null>)
            }
            disabled={disabled}
            aria-label={name}
            className={cn(
                'grid gap-2 rounded-lg p-0.5',
                highlighted &&
                    'ring-2 ring-primary/35 ring-offset-2 ring-offset-background motion-safe:bg-primary/5',
            )}
        >
            <DecisionCard
                value="none"
                selected={value === 'none'}
                disabled={disabled}
                title={noneTitle}
            />
            <DecisionCard
                value="preset"
                selected={value === 'preset'}
                disabled={disabled}
                title={presetTitle}
                description={
                    value === 'preset' ? (presetDescription ?? null) : null
                }
            />
        </RadioGroup>
    );
}

function DecisionCard({
    value,
    selected,
    disabled,
    title,
    description,
}: {
    value: 'none' | 'preset';
    selected: boolean;
    disabled: boolean;
    title: string;
    description?: string | null;
}) {
    return (
        <RadioItem
            value={value}
            disabled={disabled}
            data-selected={selected ? 'true' : 'false'}
            className={cn(
                'flex items-start gap-2.5 rounded-lg border px-2.5 py-2 text-left outline-none',
                'focus-visible:ring-2 focus-visible:ring-ring/50',
                disabled ? 'cursor-default' : 'cursor-pointer',
                selected
                    ? 'border-primary bg-primary/8 shadow-xs'
                    : 'border-border/70 bg-transparent hover:bg-muted/40',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'mt-0.5 flex size-3.5 shrink-0 items-center justify-center rounded-full border',
                    selected
                        ? 'border-primary bg-primary'
                        : 'border-muted-foreground/45 bg-background',
                )}
            >
                {selected ? (
                    <span className="size-1.5 rounded-full bg-primary-foreground" />
                ) : null}
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block text-xs',
                        selected
                            ? 'font-semibold text-foreground'
                            : 'font-medium text-foreground/80',
                    )}
                >
                    {title}
                </span>
                {description ? (
                    <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                        {description}
                    </span>
                ) : null}
            </span>
        </RadioItem>
    );
}
