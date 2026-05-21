import { UserPlus } from 'lucide-react';
import React from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { PhoneInputWithCountry } from '@/components/phone-input-with-country';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import type {
    CreatableMasterDataContext,
    CreatableMasterDataKey,
} from '@/lib/master-data/creatable-registry';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';

type Option = {
    id: number | string;
    name: string;
    title?: string;
    code?: string;
    dial_code?: string | null;
};

const PHONE_FIELD_KEYS = new Set([
    'phone',
    'emergency_phone',
    'phone_home_country',
]);

function CreatableMasterDataFieldSelect({
    id,
    label,
    isRequired,
    value,
    error,
    onChange,
    options,
    placeholder,
    creatableKey,
    creatableContext,
    labelKey = 'name',
}: {
    id: string;
    label: string;
    isRequired: boolean;
    value: unknown;
    error?: string;
    onChange: (value: string) => void;
    options: Option[];
    placeholder: string;
    creatableKey: CreatableMasterDataKey;
    creatableContext?: CreatableMasterDataContext;
    labelKey?: 'name' | 'title';
}): React.ReactElement {
    const { selectOptions, appendOption } = useMutableSelectOptions(options, labelKey);
    const { canCreate, createConfig } = useCreatableMasterData(creatableKey, creatableContext);

    return (
        <div className="space-y-1.5">
            <Label htmlFor={id} className="text-xs font-medium text-foreground">
                {label} {isRequired && <span className="text-destructive">*</span>}
            </Label>
            <CreatableSelect
                value={String(value ?? '')}
                onValueChange={onChange}
                variant="card"
                placeholder={placeholder}
                options={selectOptions}
                onOptionsChange={(next) => {
                    const added = next.find(
                        (option) =>
                            !selectOptions.some((existing) => existing.value === option.value),
                    );

                    if (added) {
                        appendOption({
                            id: added.id,
                            label: added.label,
                        });
                    }
                }}
                creatable
                canCreate={canCreate}
                createConfig={createConfig}
            />
            {error ? <p className="text-[10px] text-destructive">{error}</p> : null}
        </div>
    );
}

interface FieldRendererProps {
    fieldKey: string;
    isRequired: boolean;
    value: any;
    error?: string;
    onChange: (value: any) => void;
    options: {
        branches: Option[];
        departments: Option[];
        positions: Option[];
        managers: Option[];
        countries: Option[];
        religions: Option[];
        genders: Option[];
        visa_types: Option[];
        banks: Option[];
        ranks: Option[];
    };
    imagePreview?: string | null;
    setImagePreview?: (url: string | null) => void;
    formDepartmentId?: string;
}

export function FieldRenderer({
    fieldKey,
    isRequired,
    value,
    error,
    onChange,
    options,
    imagePreview,
    setImagePreview,
    formDepartmentId
}: FieldRendererProps) {
    const id = fieldKey;
    
    const labelFromKey = (key: string) => {
        const labelKey = key.endsWith('_id') ? key.slice(0, -3) : key;

        return labelKey.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
    };

    const label = labelFromKey(fieldKey);
    const inputClass = 'h-10 rounded-lg bg-background border-input focus:ring-1 focus:ring-primary transition-all';

    if (fieldKey === 'image') {
        return (
            <div className="space-y-2">
                <Label className="text-xs font-medium text-foreground">
                    Image {isRequired && <span className="text-destructive">*</span>}
                </Label>

                <div className="rounded-xl border border-border bg-card/30 p-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
                        <div className="h-28 w-28 rounded-xl border border-border bg-muted/20 overflow-hidden flex items-center justify-center">
                            {imagePreview ? (
                                <img src={imagePreview} alt="Preview" className="h-full w-full object-cover" />
                            ) : (
                                <UserPlus className="h-8 w-8 text-muted-foreground/70" />
                            )}
                        </div>

                        <div className="flex-1 space-y-2">
                            <input
                                id={id}
                                type="file"
                                accept="image/*"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    onChange(file);

                                    if (setImagePreview) {
                                        setImagePreview(file ? URL.createObjectURL(file) : null);
                                    }
                                }}
                                className="block w-full text-sm text-muted-foreground file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                            />
                            <div className="text-[11px] text-muted-foreground">PNG/JPG up to 4MB.</div>
                            {error && <p className="text-[10px] text-destructive">{error}</p>}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    // Special handling for dropdowns
    const renderSelect = (opts: Option[], placeholder: string) => (
        <div className="space-y-1.5">
            <Label htmlFor={id} className="text-xs font-medium text-foreground">
                {label} {isRequired && <span className="text-destructive">*</span>}
            </Label>
            <AppSelect
                value={String(value ?? '')}
                onValueChange={(v) => onChange(v)}
                variant="card"
                placeholder={placeholder}
            >
                <AppSelectItem value="">{placeholder}</AppSelectItem>
                {opts.map((o) => (
                    <AppSelectItem key={o.id} value={String(o.id)}>
                        {o.title || o.name}
                    </AppSelectItem>
                ))}
            </AppSelect>
            {error && <p className="text-[10px] text-destructive">{error}</p>}
        </div>
    );

    if (fieldKey === 'branch_id') {
        return renderSelect(options.branches, 'Select Branch');
    }

    if (fieldKey === 'department_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.departments}
                placeholder="Select Department"
                creatableKey="department"
            />
        );
    }

    if (fieldKey === 'position_id') {
        const filteredPositions = options.positions.filter(
            (p) => !formDepartmentId || String((p as Option & { department_id?: number }).department_id) === String(formDepartmentId),
        );

        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={filteredPositions}
                placeholder="Select Position"
                creatableKey="position"
                creatableContext={{ departmentId: formDepartmentId }}
                labelKey="title"
            />
        );
    }

    if (fieldKey === 'rank_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.ranks}
                placeholder="Select Rank"
                creatableKey="rank"
            />
        );
    }

    if (fieldKey === 'manager_id') {
        const managers = options.managers.map((m) => ({ ...m, name: m.name }));

        return renderSelect(managers, 'Select Manager');
    }

    if (fieldKey === 'gender_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.genders}
                placeholder="Select Gender"
                creatableKey="gender"
            />
        );
    }

    if (fieldKey === 'religion_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.religions}
                placeholder="Select Religion"
                creatableKey="religion"
            />
        );
    }

    if (fieldKey === 'visa_type_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.visa_types}
                placeholder="Select visa type"
                creatableKey="visaType"
            />
        );
    }

    if (fieldKey === 'bank_id') {
        return (
            <CreatableMasterDataFieldSelect
                id={id}
                label={label}
                isRequired={isRequired}
                value={value}
                error={error}
                onChange={onChange}
                options={options.banks}
                placeholder="Select Bank"
                creatableKey="bank"
            />
        );
    }
    
    if (fieldKey === 'nationality_id') {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <AppSelect
                    value={String(value ?? '')}
                    onValueChange={(v) => onChange(v)}
                    variant="card"
                    placeholder="Select Nationality"
                >
                    <AppSelectItem value="">Select Nationality</AppSelectItem>
                    {options.countries.map((o) => (
                        <AppSelectItem key={o.id} value={String(o.id)}>
                            {o.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    if (fieldKey === 'contract_type') {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <AppSelect
                    value={String(value ?? '')}
                    onValueChange={(v) => onChange(v)}
                    variant="card"
                    placeholder="Limited"
                >
                    <AppSelectItem value="limited">Limited</AppSelectItem>
                    <AppSelectItem value="unlimited">Unlimited</AppSelectItem>
                    <AppSelectItem value="part_time">Part Time</AppSelectItem>
                    <AppSelectItem value="contract">Contract</AppSelectItem>
                </AppSelect>
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    if (fieldKey === 'marital_status') {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <AppSelect
                    value={String(value ?? '')}
                    onValueChange={(v) => onChange(v)}
                    variant="card"
                    placeholder="Select Status"
                >
                    <AppSelectItem value="">Select Status</AppSelectItem>
                    <AppSelectItem value="single">Single</AppSelectItem>
                    <AppSelectItem value="married">Married</AppSelectItem>
                    <AppSelectItem value="divorced">Divorced</AppSelectItem>
                    <AppSelectItem value="widowed">Widowed</AppSelectItem>
                </AppSelect>
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    // Date fields
    if (fieldKey.includes('date') || fieldKey.includes('birthdate')) {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <Input
                    type="date"
                    id={id}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    className={inputClass}
                    required={isRequired}
                />
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    // Numeric fields
    if (fieldKey.includes('salary') || fieldKey.includes('allowance') || fieldKey.includes('count')) {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <Input
                    type="number"
                    id={id}
                    placeholder={label}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    className={inputClass}
                    required={isRequired}
                />
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    if (PHONE_FIELD_KEYS.has(fieldKey)) {
        const phoneCountries = (options.countries ?? []) as PhoneCountryOption[];

        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <PhoneInputWithCountry
                    id={id}
                    countries={phoneCountries}
                    value={value ?? ''}
                    onChange={onChange}
                    fieldKey={fieldKey}
                    inputClassName="rounded-lg border-input bg-background"
                    selectClassName="rounded-lg border border-input bg-background"
                />
                {error && <p className="text-[10px] text-destructive">{error}</p>}
            </div>
        );
    }

    const inputType = (fieldKey === 'work_email' || fieldKey === 'personal_email') ? 'email' : 'text';

    return (
        <div className="space-y-1.5">
            <Label htmlFor={id} className="text-xs font-medium text-foreground">
                {label} {isRequired && <span className="text-destructive">*</span>}
            </Label>
            <Input
                id={id}
                type={inputType}
                placeholder={label}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={inputClass}
                required={isRequired}
            />
            {error && <p className="text-[10px] text-destructive">{error}</p>}
        </div>
    );
}
