import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UserPlus } from 'lucide-react';
import React from 'react';

type Option = { id: number | string; name: string; title?: string };

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
        banks: Option[];
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
    const selectClass = 'w-full rounded-lg border border-input bg-background h-10 px-3 text-sm outline-none focus:ring-1 focus:ring-primary transition-all';
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
            <select
                id={id}
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
                className={selectClass}
                required={isRequired}
            >
                <option value="">{placeholder}</option>
                {opts.map((o) => (
                    <option key={o.id} value={String(o.id)}>
                        {o.title || o.name || (o as any).first_name + ' ' + (o as any).last_name}
                    </option>
                ))}
            </select>
            {error && <p className="text-[10px] text-destructive">{error}</p>}
        </div>
    );

    if (fieldKey === 'branch_id') return renderSelect(options.branches, 'Select Branch');
    if (fieldKey === 'department_id') return renderSelect(options.departments, 'Select Department');
    if (fieldKey === 'position_id') {
        const filteredPositions = options.positions.filter(
            (p) => !formDepartmentId || String((p as any).department_id) === String(formDepartmentId)
        );
        return renderSelect(filteredPositions, 'Select Position');
    }
    if (fieldKey === 'manager_id') {
        const managers = options.managers.map(m => ({ ...m, name: `${(m as any).first_name} ${(m as any).last_name}` }));
        return renderSelect(managers, 'Select Manager');
    }
    if (fieldKey === 'gender_id') return renderSelect(options.genders, 'Select Gender');
    if (fieldKey === 'religion_id') return renderSelect(options.religions, 'Select Religion');
    if (fieldKey === 'bank_id') return renderSelect(options.banks, 'Select Bank');
    
    if (fieldKey === 'nationality') {
        return (
            <div className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <select
                    id={id}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    className={selectClass}
                    required={isRequired}
                >
                    <option value="">Select Nationality</option>
                    {options.countries.map((o) => (
                        <option key={o.id} value={o.name}>{o.name}</option>
                    ))}
                </select>
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
                <select
                    id={id}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    className={selectClass}
                    required={isRequired}
                >
                    <option value="limited">Limited</option>
                    <option value="unlimited">Unlimited</option>
                    <option value="part_time">Part Time</option>
                    <option value="contract">Contract</option>
                </select>
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
                <select
                    id={id}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    className={selectClass}
                    required={isRequired}
                >
                    <option value="">Select Status</option>
                    <option value="single">Single</option>
                    <option value="married">Married</option>
                    <option value="divorced">Divorced</option>
                    <option value="widowed">Widowed</option>
                </select>
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

    // Default text input
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id} className="text-xs font-medium text-foreground">
                {label} {isRequired && <span className="text-destructive">*</span>}
            </Label>
            <Input
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
