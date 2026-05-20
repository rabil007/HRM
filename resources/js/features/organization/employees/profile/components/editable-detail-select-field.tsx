import type { ReactElement, ReactNode } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EditableDetailField } from '@/features/organization/employees/profile/components/editable-detail-field';

export type EditableSelectOption = {
    id: number;
    label: string;
    value: string;
};

export type EditableDetailSelectFieldProps = {
    label: ReactNode;
    field: string;
    value: string;
    displayValue: string;
    options: EditableSelectOption[];
    activeField: string | null;
    setActiveField: (value: string | null) => void;
    beginEdit: (field: string) => void;
    canEdit: boolean;
    onChange: (value: string) => void;
    placeholder?: string;
    size?: 'sm' | 'default';
};

export function EditableDetailSelectField({
    label,
    field,
    value,
    displayValue,
    options,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onChange,
    placeholder = '—',
    size = 'sm',
}: EditableDetailSelectFieldProps): ReactElement {
    return (
        <EditableDetailField
            label={label}
            field={field}
            displayValue={displayValue}
            activeField={activeField}
            beginEdit={beginEdit}
            canEdit={canEdit}
            editControl={
                <AppSelect
                    value={value}
                    onValueChange={(next) => {
                        onChange(next);
                        setActiveField(null);
                    }}
                    onClose={() => setActiveField(null)}
                    variant="dark"
                    placeholder={placeholder}
                    size={size}
                >
                    <AppSelectItem value="">{placeholder}</AppSelectItem>
                    {options.map((option) => (
                        <AppSelectItem key={option.id} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            }
        />
    );
}
