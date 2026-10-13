import type { ReactElement } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    ALREADY_ARRIVED_EXPLANATION,
    ALREADY_ARRIVED_LABEL,
} from '@/features/organization/crew/lib/already-arrived-start';
import { cn } from '@/lib/utils';

export function AlreadyArrivedCheckbox({
    checked,
    onCheckedChange,
    id = 'already-arrived',
    className,
    showExplanation = true,
}: {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    id?: string;
    className?: string;
    showExplanation?: boolean;
}): ReactElement {
    return (
        <div className={cn('space-y-2', className)}>
            <div className="flex items-start gap-3">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(value) => onCheckedChange(value === true)}
                    className="mt-0.5"
                />
                <Label
                    htmlFor={id}
                    className="cursor-pointer text-sm leading-snug font-medium"
                >
                    {ALREADY_ARRIVED_LABEL}
                </Label>
            </div>
            {checked && showExplanation ? (
                <p className="text-xs text-muted-foreground">
                    {ALREADY_ARRIVED_EXPLANATION}
                </p>
            ) : null}
        </div>
    );
}
