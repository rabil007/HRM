import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function LinkedHotelDateField({
    id,
    label,
    checkboxLabel,
    value,
    linked,
    error,
    onLinkedChange,
    onValueChange,
}: {
    id: string;
    label: string;
    checkboxLabel: string;
    value: string;
    linked: boolean;
    error?: string;
    onLinkedChange: (linked: boolean) => void;
    onValueChange: (value: string) => void;
}): ReactElement {
    return (
        <div className="space-y-2" data-slot="linked-hotel-date">
            <div className="flex items-start gap-3">
                <Checkbox
                    id={`${id}-same-date`}
                    checked={linked}
                    onCheckedChange={(checked) =>
                        onLinkedChange(checked === true)
                    }
                />
                <Label
                    htmlFor={`${id}-same-date`}
                    className="leading-5 font-normal"
                >
                    {checkboxLabel}
                </Label>
            </div>
            <div className="space-y-2">
                <Label htmlFor={id}>
                    {label} <span className="text-destructive">*</span>
                </Label>
                <Input
                    id={id}
                    type="date"
                    value={value}
                    readOnly={linked}
                    aria-readonly={linked}
                    onChange={(event) => onValueChange(event.target.value)}
                />
                <InputError message={error} />
            </div>
        </div>
    );
}
