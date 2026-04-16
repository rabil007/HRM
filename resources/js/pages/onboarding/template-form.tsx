import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

const generateId = () => Math.random().toString(36).substring(2, 9);

export type Template = {
    id: number;
    name: string;
    description: string | null;
    tasks: unknown;
    is_default: boolean;
    created_at: string;
};

export type FormData = {
    name: string;
    description: string;
    is_default: boolean;
    tasks_json: string;
};

export type DocsRequirement = { type: string; min: number };

export type StageBuilder = {
    id: string; // Internal React key for tracking active state
    key: string;
    label: string;
    employee_fields: string[];
    contract_fields: string[];
    documents: DocsRequirement[];
};

export type BuilderState = {
    stages: StageBuilder[];
};

export const profileFieldOptions = [
    { key: 'employee_no', label: 'Employee No' },
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'date_of_birth', label: 'Date of Birth' },
    { key: 'gender', label: 'Gender' },
    { key: 'nationality', label: 'Nationality' },
    { key: 'marital_status', label: 'Marital Status' },
    { key: 'personal_email', label: 'Personal Email' },
    { key: 'work_email', label: 'Work Email' },
    { key: 'phone', label: 'Phone' },
    { key: 'emergency_contact', label: 'Emergency Contact' },
    { key: 'emergency_phone', label: 'Emergency Phone' },
    { key: 'address', label: 'Address' },
    { key: 'hire_date', label: 'Hire Date' },
    { key: 'branch_id', label: 'Branch' },
    { key: 'department_id', label: 'Department' },
    { key: 'position_id', label: 'Position' },
    { key: 'manager_id', label: 'Manager' },
    { key: 'basic_salary', label: 'Basic Salary' },
    { key: 'housing_allowance', label: 'Housing Allowance' },
    { key: 'transport_allowance', label: 'Transport Allowance' },
    { key: 'other_allowances', label: 'Other Allowances' },
    { key: 'bank_name', label: 'Bank Name' },
    { key: 'bank_account_name', label: 'Bank Account Name' },
    { key: 'iban', label: 'IBAN' },
    { key: 'visa_number', label: 'Visa Number' },
    { key: 'visa_expiry', label: 'Visa Expiry' },
    { key: 'visa_type', label: 'Visa Type' },
    { key: 'emirates_id', label: 'Emirates ID' },
    { key: 'emirates_id_expiry', label: 'Emirates ID Expiry' },
    { key: 'passport_number', label: 'Passport Number' },
    { key: 'passport_expiry', label: 'Passport Expiry' },
    { key: 'work_permit_number', label: 'Work Permit Number' },
    { key: 'work_permit_expiry', label: 'Work Permit Expiry' },
    { key: 'labor_card_number', label: 'Labor Card Number' },
    { key: 'labor_card_expiry', label: 'Labor Card Expiry' },
    { key: 'mohre_uid', label: 'MOHRE UID' },
] as const;

export const contractFieldOptions = [
    { key: 'contract_type', label: 'Contract Type' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    { key: 'probation_end_date', label: 'Probation End Date' },
    { key: 'labor_contract_id', label: 'Labor Contract ID' },
] as const;

export const docTypeOptions = [
    { key: 'passport_copy', label: 'Passport copy' },
    { key: 'photo', label: 'Photo' },
    { key: 'visa_copy', label: 'Visa copy' },
    { key: 'eid_copy', label: 'Emirates ID copy' },
] as const;

export function toBuilderState(tasks: unknown): BuilderState {
    const fallback: BuilderState = {
        stages: [
            { 
                id: generateId(), 
                key: 'profile_info', 
                label: 'Profile Information', 
                employee_fields: ['employee_no', 'first_name', 'last_name', 'work_email', 'phone', 'nationality', 'basic_salary'],
                contract_fields: [],
                documents: []
            },
            { 
                id: generateId(), 
                key: 'contract_docs', 
                label: 'Contract & Documents', 
                employee_fields: [],
                contract_fields: ['contract_type', 'start_date'],
                documents: [
                    { type: 'passport_copy', min: 1 },
                    { type: 'photo', min: 1 },
                ]
            },
        ],
    };

    if (!tasks || typeof tasks !== 'object') {
        return fallback;
    }

    const t = tasks as any;

    if (t.version === 2 && Array.isArray(t.stages)) {
        return {
            stages: t.stages.map((s: any) => ({
                id: generateId(),
                key: String(s.key || '').trim(),
                label: String(s.label || '').trim() || String(s.key || '').trim(),
                employee_fields: Array.isArray(s.employee_fields) ? s.employee_fields : [],
                contract_fields: Array.isArray(s.contract_fields) ? s.contract_fields : [],
                documents: Array.isArray(s.documents) ? s.documents : [],
            }))
        };
    }

    // Auto-migrate from v1
    if (t.version === 1 && Array.isArray(t.stages) && typeof t.modules === 'object') {
        const v1Profile = t.modules?.profile?.required_fields || [];
        const v1Contract = t.modules?.contract?.required_fields || [];
        const v1Docs = t.modules?.documents?.required_docs || [];

        return {
            stages: t.stages.map((s: any) => ({
                id: generateId(),
                key: String(s.key || '').trim(),
                label: String(s.label || '').trim() || String(s.key || '').trim(),
                employee_fields: Array.isArray(s.modules) && s.modules.includes('profile') ? v1Profile : [],
                contract_fields: Array.isArray(s.modules) && s.modules.includes('contract') ? v1Contract : [],
                documents: Array.isArray(s.modules) && s.modules.includes('documents') ? v1Docs : [],
            }))
        };
    }

    return fallback;
}

export function buildTasksFromBuilder(builder: BuilderState) {
    return {
        version: 2,
        stages: builder.stages.map((s) => ({
            key: s.key.trim(),
            label: (s.label || s.key).trim(),
            employee_fields: s.employee_fields,
            contract_fields: s.contract_fields,
            documents: s.documents.map((d) => ({ type: d.type, min: d.min }))
        }))
    };
}

export function TemplateForm({ 
    template, 
    onCancel 
}: { 
    template?: Template | null; 
    onCancel?: () => void 
}) {
    const [builder, setBuilder] = useState<BuilderState>(() => toBuilderState(template?.tasks));
    const [activeStageId, setActiveStageId] = useState<string | null>(() => builder.stages[0]?.id || null);

    const [draggedIdx, setDraggedIdx] = useState<number | null>(null);
    const [dragOverIdx, setDragOverIdx] = useState<number | null>(null);

    const reorderStages = (startIndex: number, endIndex: number) => {
        setBuilder(prev => {
            const newStages = Array.from(prev.stages);
            const [removed] = newStages.splice(startIndex, 1);
            newStages.splice(endIndex, 0, removed);
            return { stages: newStages };
        });
    };

    const form = useForm<FormData>({
        name: template?.name ?? '',
        description: template?.description ?? '',
        is_default: template?.is_default ?? false,
        tasks_json: JSON.stringify(buildTasksFromBuilder(toBuilderState(template?.tasks)), null, 2),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        
        form.transform((data) => ({
            ...data,
            tasks_json: JSON.stringify(buildTasksFromBuilder(builder), null, 2)
        }));

        if (template) {
            form.put(`/onboarding/templates/${template.id}`);
        } else {
            form.post('/onboarding/templates');
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
            <div className="max-w-7xl mx-auto space-y-8">
                <div className="glass-card p-6 space-y-4">
                    <div className="text-sm font-semibold uppercase tracking-wider text-muted-foreground/70">Basic Information</div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">Template Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g., Standard Onboarding"
                                className="h-11 rounded-xl"
                            />
                            {form.errors.name && <div className="text-xs text-destructive">{form.errors.name}</div>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="is_default">Default Template</Label>
                            <div className="h-11 rounded-xl border border-border bg-card/50 px-3 flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Use for new employees</span>
                                <Switch
                                    checked={form.data.is_default}
                                    onCheckedChange={(v) => form.setData('is_default', v)}
                                />
                            </div>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="description">Description</Label>
                        <Input
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder="Describe the purpose of this template"
                            className="h-11 rounded-xl"
                        />
                        {form.errors.description && <div className="text-xs text-destructive">{form.errors.description}</div>}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    {/* Left Panel: Stages List */}
                    <div className="glass-card p-0 flex flex-col lg:col-span-4 overflow-hidden border-border/50">
                        <div className="p-4 border-b border-border/50 flex items-center justify-between bg-card/40">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground/70">Workflow Stages</h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    const newStage = {
                                        id: generateId(),
                                        key: `stage_${builder.stages.length + 1}`,
                                        label: `Stage ${builder.stages.length + 1}`,
                                        employee_fields: [],
                                        contract_fields: [],
                                        documents: []
                                    };
                                    setBuilder(prev => ({ stages: [...prev.stages, newStage] }));
                                    setActiveStageId(newStage.id);
                                }}
                            >Add Stage</Button>
                        </div>
                        <div className="flex flex-col p-2 space-y-1 max-h-[600px] overflow-y-auto">
                            {builder.stages.map((s, idx) => {
                                const isActive = s.id === activeStageId;
                                const isDragging = draggedIdx === idx;
                                const isDragOver = dragOverIdx === idx;

                                return (
                                    <button
                                        key={s.id}
                                        type="button"
                                        draggable
                                        onDragStart={(e) => {
                                            setDraggedIdx(idx);
                                            e.dataTransfer.effectAllowed = 'move';
                                            e.dataTransfer.setData('text/html', e.currentTarget.outerHTML);
                                        }}
                                        onDragOver={(e) => {
                                            e.preventDefault();
                                            setDragOverIdx(idx);
                                        }}
                                        onDragLeave={() => {
                                            if (dragOverIdx === idx) setDragOverIdx(null);
                                        }}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            if (draggedIdx !== null && draggedIdx !== idx) {
                                                reorderStages(draggedIdx, idx);
                                            }
                                            setDraggedIdx(null);
                                            setDragOverIdx(null);
                                        }}
                                        onDragEnd={() => {
                                            setDraggedIdx(null);
                                            setDragOverIdx(null);
                                        }}
                                        onClick={() => setActiveStageId(s.id)}
                                        className={`flex flex-col text-left p-3 rounded-xl border transition-all cursor-move ${isActive ? 'bg-primary/20 border-primary/50 shadow-sm' : 'border-transparent hover:bg-muted/40'} ${isDragging ? 'opacity-50' : 'opacity-100'} ${isDragOver && draggedIdx !== idx ? (draggedIdx !== null && draggedIdx < idx ? 'border-b-2 border-b-primary shadow-md' : 'border-t-2 border-t-primary shadow-md') : ''}`}
                                    >
                                        <div className="flex items-center justify-between w-full">
                                            <span className={`font-semibold text-sm flex items-center gap-2 ${isActive ? 'text-primary' : 'text-foreground'}`}>
                                                <svg className="w-4 h-4 opacity-40 cursor-grab active:cursor-grabbing" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 8h16M4 16h16" /></svg>
                                                {s.label || s.key}
                                            </span>
                                        </div>
                                        <div className="text-[10px] uppercase font-bold text-muted-foreground/70 mt-2 flex gap-3 pl-6">
                                            <span>{s.employee_fields.length} Profile</span>
                                            <span>{s.contract_fields.length} Contract</span>
                                            <span>{s.documents.length} Docs</span>
                                        </div>
                                    </button>
                                );
                            })}
                            {builder.stages.length === 0 && (
                                <div className="p-6 text-center text-sm text-muted-foreground">No stages defined.</div>
                            )}
                        </div>
                    </div>

                    {/* Right Panel: Stage Configuration */}
                    {activeStageId && builder.stages.some(s => s.id === activeStageId) ? (
                        (() => {
                            const activeStageIndex = builder.stages.findIndex(s => s.id === activeStageId);
                            const s = builder.stages[activeStageIndex];
                            
                            const updateStage = (data: Partial<StageBuilder>) => {
                                setBuilder(prev => {
                                    const newStages = [...prev.stages];
                                    newStages[activeStageIndex] = { ...newStages[activeStageIndex], ...data };

                                    return { stages: newStages };
                                });
                            };

                            return (
                                <div className="glass-card p-6 space-y-6 lg:col-span-8">
                                    <div className="flex items-center justify-between pb-4 border-b border-border/40">
                                        <div className="space-y-1">
                                            <h3 className="text-lg font-semibold tracking-tight">{s.label || 'Untitled Stage'}</h3>
                                            <p className="text-xs text-muted-foreground">Configure the properties requested from the user during this stage.</p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:bg-destructive/10"
                                            onClick={() => {
                                                const nextStages = builder.stages.filter(x => x.id !== activeStageId);
                                                setBuilder({ stages: nextStages });

                                                if (nextStages.length > 0) {
                                                    setActiveStageId(nextStages[0].id);
                                                } else {
                                                    setActiveStageId(null);
                                                }
                                            }}
                                        >
                                            Delete Stage
                                        </Button>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label className="text-xs">Stage Label (Display Name)</Label>
                                            <Input
                                                value={s.label}
                                                onChange={e => updateStage({ label: e.target.value })}
                                                className="h-10 text-sm"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label className="text-xs">Unique ID (System Key)</Label>
                                            <Input
                                                value={s.key}
                                                onChange={e => updateStage({ key: e.target.value })}
                                                className="h-10 text-sm"
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-border/40">
                                        <div className="space-y-3">
                                            <Label className="text-sm font-medium flex justify-between items-center">
                                                Employee Profile Elements
                                                <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.employee_fields.length} sel</span>
                                            </Label>
                                            <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[350px] overflow-y-auto">
                                                {profileFieldOptions.map((f) => (
                                                    <label key={f.key} className="flex items-center gap-2 text-sm cursor-pointer group">
                                                        <input
                                                            type="checkbox"
                                                            className="rounded border-border/50 text-primary focus:ring-primary/20"
                                                            checked={s.employee_fields.includes(f.key)}
                                                            onChange={(e) => {
                                                                const next = e.target.checked;
                                                                updateStage({
                                                                    employee_fields: next 
                                                                        ? [...s.employee_fields, f.key] 
                                                                        : s.employee_fields.filter(k => k !== f.key)
                                                                });
                                                            }}
                                                        />
                                                        <span className="group-hover:text-primary transition-colors">{f.label}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="space-y-6">
                                            <div className="space-y-3">
                                                <Label className="text-sm font-medium flex justify-between items-center">
                                                    Contract Properties
                                                    <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.contract_fields.length} sel</span>
                                                </Label>
                                                <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[170px] overflow-y-auto">
                                                    {contractFieldOptions.map((f) => (
                                                        <label key={f.key} className="flex items-center gap-2 text-sm cursor-pointer group">
                                                            <input
                                                                type="checkbox"
                                                                className="rounded border-border/50 text-primary focus:ring-primary/20"
                                                                checked={s.contract_fields.includes(f.key)}
                                                                onChange={(e) => {
                                                                    const next = e.target.checked;
                                                                    updateStage({
                                                                        contract_fields: next 
                                                                            ? [...s.contract_fields, f.key] 
                                                                            : s.contract_fields.filter(k => k !== f.key)
                                                                });
                                                            }}
                                                        />
                                                        <span className="group-hover:text-primary transition-colors">{f.label}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="space-y-3 pt-4 border-t border-border/40">
                                            <div className="flex flex-row items-center justify-between">
                                                <Label className="text-sm font-medium flex items-center gap-2">
                                                    Required Documents
                                                    <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.documents.length} sel</span>
                                                </Label>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 text-xs px-2"
                                                    onClick={() => {
                                                        const first = docTypeOptions[0]?.key ?? 'passport_copy';
                                                        updateStage({ documents: [...s.documents, { type: first, min: 1 }] });
                                                    }}
                                                >
                                                    Add Doc
                                                </Button>
                                            </div>
                                            <div className="space-y-2 max-h-[120px] overflow-y-auto pr-1">
                                                {s.documents.length === 0 && (
                                                    <p className="text-[11px] text-muted-foreground py-2 italic text-center rounded-xl border border-dashed border-border/50 bg-muted/10">No documents required for this stage.</p>
                                                )}
                                                {s.documents.map((d, dIdx) => (
                                                    <div key={`${d.type}-${dIdx}`} className="flex gap-2 items-center">
                                                        <div className="flex-1">
                                                            <select
                                                                value={d.type}
                                                                onChange={(e) => {
                                                                    const v = e.target.value;
                                                                    const newDocs = [...s.documents];
                                                                    newDocs[dIdx] = { ...newDocs[dIdx], type: v };
                                                                    updateStage({ documents: newDocs });
                                                                }}
                                                                className="w-full rounded-lg border border-border bg-card/50 h-9 px-2 text-xs"
                                                            >
                                                                {docTypeOptions.map((o) => (
                                                                    <option key={o.key} value={o.key}>{o.label}</option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                        <div className="w-16">
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                value={String(d.min)}
                                                                onChange={(e) => {
                                                                    const v = Number(e.target.value || 1);
                                                                    const newDocs = [...s.documents];
                                                                    newDocs[dIdx] = { ...newDocs[dIdx], min: v };
                                                                    updateStage({ documents: newDocs });
                                                                }}
                                                                className="h-9 rounded-lg px-2 text-xs"
                                                                placeholder="Qty"
                                                            />
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            variant="destructive"
                                                            size="icon"
                                                            className="h-8 w-8 shrink-0"
                                                            onClick={() => {
                                                                const newDocs = s.documents.filter((_, i) => i !== dIdx);
                                                                updateStage({ documents: newDocs });
                                                            }}
                                                        >
                                                            &times;
                                                        </Button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })()
                ) : (
                    <div className="glass-card p-6 lg:col-span-8 flex flex-col items-center justify-center text-muted-foreground h-[400px]">
                        <svg className="w-16 h-16 opacity-30 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        <p>Select a stage from the left to configure it.</p>
                    </div>
                )}
            </div>

            <div className="flex flex-col-reverse md:flex-row items-center justify-end gap-3 pt-6 pb-12">
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel} className="w-full md:w-40 h-12 rounded-xl text-muted-foreground hover:text-foreground">
                        Discard Changes
                    </Button>
                )}
                <Button type="submit" className="w-full md:w-64 h-12 rounded-xl text-lg font-semibold shadow-lg shadow-primary/20" disabled={form.processing}>
                    {template ? 'Update Template' : 'Create Template'}
                </Button>
            </div>
        </div>
    </form>
);
}
