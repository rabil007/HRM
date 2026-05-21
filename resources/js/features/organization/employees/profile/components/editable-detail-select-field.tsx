import type { ReactElement, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { CreatableSelect, type CreatableOption } from '@/components/ui/creatable-select';
import { EditableDetailField } from '@/features/organization/employees/profile/components/editable-detail-field';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import type { CreatableMasterDataContext, CreatableMasterDataKey } from '@/lib/master-data/creatable-registry';

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
    onOptionsChange?: (options: EditableSelectOption[]) => void;
    creatableKey?: CreatableMasterDataKey;
    creatableContext?: CreatableMasterDataContext;
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
    onOptionsChange,
    creatableKey,
    creatableContext,
    activeField,
    setActiveField,
    beginEdit,
    canEdit,
    onChange,
    placeholder = '—',
    size = 'sm',
}: EditableDetailSelectFieldProps): ReactElement {
    const [localOptions, setLocalOptions] = useState<CreatableOption[]>(options);
    const creatable = Boolean(creatableKey);
    const { canCreate, createConfig } = useCreatableMasterData(
        creatableKey ?? 'bank',
        creatableContext,
    );

    useEffect(() => {
        setLocalOptions(options);
    }, [options]);

    const handleOptionsChange = (next: CreatableOption[]): void => {
        setLocalOptions(next);
        onOptionsChange?.(
            next.map((option) => ({
                id: Number(option.id),
                label: option.label,
                value: option.value,
            })),
        );
    };

    return (
        <EditableDetailField
            label={label}
            field={field}
            displayValue={displayValue}
            activeField={activeField}
            beginEdit={beginEdit}
            canEdit={canEdit}
            editControl={
                <CreatableSelect
                    value={value}
                    onValueChange={(next) => {
                        onChange(next);
                        setActiveField(null);
                    }}
                    onClose={() => setActiveField(null)}
                    variant="dark"
                    placeholder={placeholder}
                    size={size}
                    options={localOptions}
                    onOptionsChange={handleOptionsChange}
                    creatable={creatable}
                    canCreate={creatable && canCreate}
                    createConfig={creatable ? createConfig : undefined}
                />
            }
        />
    );
}
