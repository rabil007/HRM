import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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

export type DocsRequirement = { 
    type: string; 
    min: number;
    ask_issue_date?: boolean;
    ask_expiry_date?: boolean;
    ask_document_number?: boolean;
};

export type FieldRequirement = { key: string; required: boolean };

export type StageBuilder = {
    id: string; // Internal React key for tracking active state
    key: string;
    label: string;
    employee_fields: FieldRequirement[];
    contract_fields: FieldRequirement[];
    documents: DocsRequirement[];
};

export type BuilderState = {
    stages: StageBuilder[];
};

export const profileFieldOptions = [
    { key: 'employee_no', label: 'Employee No' },
    { key: 'first_name', label: 'First Name' },
    { key: 'last_name', label: 'Last Name' },
    { key: 'image', label: 'Image' },
    { key: 'date_of_birth', label: 'Date of Birth' },
    { key: 'place_of_birth', label: 'Place of Birth' },
    { key: 'gender_id', label: 'Gender' },
    { key: 'nationality', label: 'Nationality' },
    { key: 'religion_id', label: 'Religion' },
    { key: 'marital_status', label: 'Marital Status' },
    { key: 'spouse_name', label: 'Spouse Name' },
    { key: 'spouse_birthdate', label: 'Spouse Birthdate' },
    { key: 'dependent_children_count', label: 'Dependent Children Count' },
    { key: 'personal_email', label: 'Personal Email' },
    { key: 'work_email', label: 'Work Email' },
    { key: 'phone', label: 'Phone' },
    { key: 'nearest_airport', label: 'Nearest Airport' },
    { key: 'phone_home_country', label: 'Phone (Home Country)' },
    { key: 'emergency_contact', label: 'Emergency Contact' },
    { key: 'emergency_phone', label: 'Emergency Phone' },
    { key: 'emergency_contact_home_country', label: 'Emergency Contact (Home Country)' },
    { key: 'emergency_phone_home_country', label: 'Emergency Phone (Home Country)' },
    { key: 'address', label: 'Address' },
    { key: 'cv_source', label: 'CV Source' },
    { key: 'branch_id', label: 'Branch' },
    { key: 'department_id', label: 'Department' },
    { key: 'position_id', label: 'Position' },
    { key: 'manager_id', label: 'Manager' },
    { key: 'bank_id', label: 'Bank' },
    { key: 'iban', label: 'IBAN' },
] as const;

export const contractFieldOptions = [
    { key: 'contract_type', label: 'Contract Type' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    { key: 'probation_end_date', label: 'Probation End Date' },
    { key: 'labor_contract_id', label: 'Labor Contract ID' },
    { key: 'basic_salary', label: 'Basic Salary' },
    { key: 'housing_allowance', label: 'Housing Allowance' },
    { key: 'transport_allowance', label: 'Transport Allowance' },
    { key: 'other_allowances', label: 'Other Allowances' },
] as const;

export type DocumentTypeModel = {
    id: number;
    title: string;
    slug: string;
};

export function toBuilderState(tasks: unknown): BuilderState {
    const mapFields = (fields: any): FieldRequirement[] => {
        if (!Array.isArray(fields)) {
return [];
}

        return fields.map(f => {
            if (typeof f === 'string') {
return { key: f, required: true };
}

            if (typeof f === 'object' && f !== null) {
return { key: f.key, required: !!f.required };
}

            return { key: '', required: true };
        }).filter(f => f.key !== '');
    };

    const fallback: BuilderState = {
        stages: [
            { 
                id: generateId(), 
                key: 'profile_info', 
                label: 'Profile Information', 
                employee_fields: mapFields(['employee_no', 'first_name', 'last_name', 'work_email', 'phone', 'nationality']),
                contract_fields: [],
                documents: []
            },
            { 
                id: generateId(), 
                key: 'contract_docs', 
                label: 'Contract & Documents', 
                employee_fields: [],
                contract_fields: ['contract_type', 'start_date'],
                documents: []
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
                employee_fields: mapFields(s.employee_fields),
                contract_fields: mapFields(s.contract_fields),
                documents: Array.isArray(s.documents)
                    ? s.documents.map((d: any) => ({
                          type: String(d?.type ?? ''),
                          min: Number(d?.min ?? 1),
                          ask_issue_date: !!d?.ask_issue_date,
                          ask_expiry_date: !!d?.ask_expiry_date,
                          ask_document_number: !!d?.ask_document_number,
                      }))
                    : [],
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
                employee_fields: Array.isArray(s.modules) && s.modules.includes('profile') ? mapFields(v1Profile) : [],
                contract_fields: Array.isArray(s.modules) && s.modules.includes('contract') ? mapFields(v1Contract) : [],
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
            documents: s.documents.map((d) => ({
                type: d.type,
                min: d.min,
                ask_issue_date: !!d.ask_issue_date,
                ask_expiry_date: !!d.ask_expiry_date,
                ask_document_number: !!d.ask_document_number,
            })),
        }))
    };
}

export function TemplateForm({ 
    template, 
    documentTypes,
    onCancel 
}: { 
    template?: Template | null; 
    documentTypes: DocumentTypeModel[];
    onCancel?: () => void 
}) {
    const [builder, setBuilder] = useState<BuilderState>(() => toBuilderState(template?.tasks));
    const [activeStageId, setActiveStageId] = useState<string | null>(() => builder.stages[0]?.id || null);
    const [docSearch, setDocSearch] = useState('');
    const [fieldSearch, setFieldSearch] = useState('');
    const [contractSearch, setContractSearch] = useState('');

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
            <div className="w-full px-4 sm:px-6 lg:px-10 space-y-8">
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
                    <div className="glass-card p-0 flex flex-col lg:col-span-3 lg:sticky lg:top-8 overflow-hidden border-border/50">
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
                        <div className="flex flex-col p-2 space-y-1 lg:max-h-[600px] lg:overflow-y-auto">
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
                                            if (dragOverIdx === idx) {
setDragOverIdx(null);
}
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

                            const otherStages = builder.stages.filter(x => x.id !== activeStageId);
                            const otherProfileFields = new Set(otherStages.flatMap(x => x.employee_fields.map(f => f.key)));
                            const otherContractFields = new Set(otherStages.flatMap(x => x.contract_fields.map(f => f.key)));
                            const otherDocuments = new Set(otherStages.flatMap(x => x.documents.map(d => String(d.type))));

                            return (
                                <div className="glass-card p-6 space-y-6 lg:col-span-9">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-border/40">
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
                                            <Label className="text-xs text-muted-foreground font-medium flex items-center justify-between">
                                                Unique ID (System Key)
                                                <svg className="w-3.5 h-3.5 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                            </Label>
                                            <Input
                                                value={s.key}
                                                disabled
                                                readOnly
                                                className="h-10 text-sm bg-muted/30 opacity-70 cursor-not-allowed border-dashed focus-visible:ring-0"
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-border/40">
                                        <div className="space-y-4">
                                            <div className="flex items-center justify-between">
                                                <Label className="text-sm font-medium flex items-center gap-2">
                                                    Employee Fields
                                                    <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.employee_fields.length} sel</span>
                                                </Label>
                                                <div className="flex flex-wrap items-center gap-2 sm:gap-3">
                                                    <Input 
                                                        placeholder="Search fields..." 
                                                        className="h-7 text-[10px] w-full sm:w-32 rounded-lg bg-card/30"
                                                        value={fieldSearch}
                                                        onChange={(e) => setFieldSearch(e.target.value)}
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-6 text-[10px] px-2 text-muted-foreground hover:text-primary uppercase tracking-wider font-semibold"
                                                        onClick={() => {
                                                            const filtered = profileFieldOptions.filter(f => 
                                                                f.label.toLowerCase().includes(fieldSearch.toLowerCase())
                                                            );
                                                            const available = filtered.filter(f => !otherProfileFields.has(f.key));
                                                            const currentlySelectedInSearch = s.employee_fields.filter(sf => 
                                                                available.some(a => a.key === sf.key)
                                                            );

                                                            if (currentlySelectedInSearch.length === available.length && available.length > 0) {
                                                                const remaining = s.employee_fields.filter(sf => !available.some(a => a.key === sf.key));
                                                                updateStage({ employee_fields: remaining });
                                                            } else {
                                                                const toAdd = available
                                                                    .filter(a => !s.employee_fields.some(sf => sf.key === a.key))
                                                                    .map(a => ({ key: a.key, required: true }));
                                                                updateStage({ employee_fields: [...s.employee_fields, ...toAdd] });
                                                            }
                                                        }}
                                                    >
                                                        {s.employee_fields.length === profileFieldOptions.filter(f => !otherProfileFields.has(f.key)).length && s.employee_fields.length > 0 ? 'Deselect All' : 'Select All'}
                                                    </Button>
                                                </div>
                                            </div>
                                            <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[350px] overflow-y-auto">
                                                {(() => {
                                                    const visible = profileFieldOptions
                                                        .filter((f) =>
                                                            f.label.toLowerCase().includes(fieldSearch.toLowerCase())
                                                        )
                                                        .filter(
                                                            (f) =>
                                                                !otherProfileFields.has(f.key) ||
                                                                s.employee_fields.some((sf) => sf.key === f.key)
                                                        );

                                                    if (visible.length === 0) {
                                                        return (
                                                            <div className="py-8 text-center text-xs text-muted-foreground">
                                                                No employee fields found.
                                                            </div>
                                                        );
                                                    }

                                                    return visible.map((f) => {
                                                        const isSelected = s.employee_fields.some((sf) => sf.key === f.key);
                                                        const reqData = s.employee_fields.find((sf) => sf.key === f.key);

                                                        return (
                                                            <div
                                                                key={f.key}
                                                                className={`flex flex-col p-2.5 rounded-lg border transition-all ${
                                                                    isSelected
                                                                        ? 'border-primary/50 bg-primary/5'
                                                                        : 'border-border/50 bg-card/30'
                                                                }`}
                                                            >
                                                                <div className="flex items-center justify-between">
                                                                    <label className="flex items-center gap-2.5 text-sm cursor-pointer group flex-1">
                                                                        <input
                                                                            type="checkbox"
                                                                            className="rounded border-border/50 text-primary w-4 h-4 focus:ring-primary/20"
                                                                            checked={isSelected}
                                                                            onChange={(e) => {
                                                                                const next = e.target.checked;
                                                                                updateStage({
                                                                                    employee_fields: next
                                                                                        ? [
                                                                                              ...s.employee_fields,
                                                                                              { key: f.key, required: true },
                                                                                          ]
                                                                                        : s.employee_fields.filter(
                                                                                              (k) => k.key !== f.key
                                                                                          ),
                                                                                });
                                                                            }}
                                                                        />
                                                                        <span className="group-hover:text-primary transition-colors font-medium">
                                                                            {f.label}
                                                                        </span>
                                                                    </label>
                                                                    {isSelected && (
                                                                        <div className="flex items-center gap-2 pl-3 border-l border-border/60">
                                                                            <span
                                                                                className={`text-[10px] uppercase font-bold ${
                                                                                    reqData?.required
                                                                                        ? 'text-primary'
                                                                                        : 'text-muted-foreground'
                                                                                }`}
                                                                            >
                                                                                {reqData?.required ? 'Req' : 'Opt'}
                                                                            </span>
                                                                            <Switch
                                                                                checked={reqData?.required ?? true}
                                                                                onCheckedChange={(val) => {
                                                                                    updateStage({
                                                                                        employee_fields: s.employee_fields.map(
                                                                                            (sf) =>
                                                                                                sf.key === f.key
                                                                                                    ? { ...sf, required: val }
                                                                                                    : sf
                                                                                        ),
                                                                                    });
                                                                                }}
                                                                                className="scale-75 data-[state=checked]:bg-primary data-[state=unchecked]:bg-muted-foreground/30"
                                                                            />
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        );
                                                    });
                                                })()}
                                            </div>
                                        </div>

                                        <div className="space-y-6">
                                            <div className="space-y-4">
                                                <div className="flex items-center justify-between">
                                                    <Label className="text-sm font-medium flex items-center gap-2">
                                                        Contract Fields
                                                        <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.contract_fields.length} sel</span>
                                                    </Label>
                                                    <div className="flex items-center gap-3">
                                                    <Input 
                                                            placeholder="Search contract..." 
                                                            className="h-7 text-[10px] w-full sm:w-32 rounded-lg bg-card/30"
                                                            value={contractSearch}
                                                            onChange={(e) => setContractSearch(e.target.value)}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-6 text-[10px] px-2 text-muted-foreground hover:text-primary uppercase tracking-wider font-semibold"
                                                            onClick={() => {
                                                                const filtered = contractFieldOptions.filter(f => 
                                                                    f.label.toLowerCase().includes(contractSearch.toLowerCase())
                                                                );
                                                                const available = filtered.filter(f => !otherContractFields.has(f.key));
                                                                const currentlySelectedInSearch = s.contract_fields.filter(sf => 
                                                                    available.some(a => a.key === sf.key)
                                                                );

                                                                if (currentlySelectedInSearch.length === available.length && available.length > 0) {
                                                                    const remaining = s.contract_fields.filter(sf => !available.some(a => a.key === sf.key));
                                                                    updateStage({ contract_fields: remaining });
                                                                } else {
                                                                    const toAdd = available
                                                                        .filter(a => !s.contract_fields.some(sf => sf.key === a.key))
                                                                        .map(a => ({ key: a.key, required: true }));
                                                                    updateStage({ contract_fields: [...s.contract_fields, ...toAdd] });
                                                                }
                                                            }}
                                                        >
                                                            {s.contract_fields.length === contractFieldOptions.filter(f => !otherContractFields.has(f.key)).length && s.contract_fields.length > 0 ? 'Deselect All' : 'Select All'}
                                                        </Button>
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-1 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[170px] overflow-y-auto">
                                                    {(() => {
                                                        const visible = contractFieldOptions
                                                            .filter((f) =>
                                                                f.label
                                                                    .toLowerCase()
                                                                    .includes(contractSearch.toLowerCase())
                                                            )
                                                            .filter(
                                                                (f) =>
                                                                    !otherContractFields.has(f.key) ||
                                                                    s.contract_fields.some((sf) => sf.key === f.key)
                                                            );

                                                        if (visible.length === 0) {
                                                            return (
                                                                <div className="py-8 text-center text-xs text-muted-foreground">
                                                                    No contract fields found.
                                                                </div>
                                                            );
                                                        }

                                                        return visible.map((f) => {
                                                            const isSelected = s.contract_fields.some(
                                                                (sf) => sf.key === f.key
                                                            );
                                                            const reqData = s.contract_fields.find(
                                                                (sf) => sf.key === f.key
                                                            );

                                                            return (
                                                                <div
                                                                    key={f.key}
                                                                    className={`flex flex-col p-2.5 rounded-lg border transition-all ${
                                                                        isSelected
                                                                            ? 'border-primary/50 bg-primary/5'
                                                                            : 'border-border/50 bg-card/30'
                                                                    }`}
                                                                >
                                                                    <div className="flex items-center justify-between">
                                                                        <label className="flex items-center gap-2.5 text-sm cursor-pointer group flex-1">
                                                                            <input
                                                                                type="checkbox"
                                                                                className="rounded border-border/50 text-primary w-4 h-4 focus:ring-primary/20"
                                                                                checked={isSelected}
                                                                                onChange={(e) => {
                                                                                    const next = e.target.checked;
                                                                                    updateStage({
                                                                                        contract_fields: next
                                                                                            ? [
                                                                                                  ...s.contract_fields,
                                                                                                  { key: f.key, required: true },
                                                                                              ]
                                                                                            : s.contract_fields.filter(
                                                                                                  (k) => k.key !== f.key
                                                                                              ),
                                                                                    });
                                                                                }}
                                                                            />
                                                                            <span className="group-hover:text-primary transition-colors font-medium">
                                                                                {f.label}
                                                                            </span>
                                                                        </label>
                                                                        {isSelected && (
                                                                            <div className="flex items-center gap-2 pl-3 border-l border-border/60">
                                                                                <span
                                                                                    className={`text-[10px] uppercase font-bold ${
                                                                                        reqData?.required
                                                                                            ? 'text-primary'
                                                                                            : 'text-muted-foreground'
                                                                                    }`}
                                                                                >
                                                                                    {reqData?.required ? 'Req' : 'Opt'}
                                                                                </span>
                                                                                <Switch
                                                                                    checked={reqData?.required ?? true}
                                                                                    onCheckedChange={(val) => {
                                                                                        updateStage({
                                                                                            contract_fields:
                                                                                                s.contract_fields.map((sf) =>
                                                                                                    sf.key === f.key
                                                                                                        ? { ...sf, required: val }
                                                                                                        : sf
                                                                                                ),
                                                                                        });
                                                                                    }}
                                                                                    className="scale-75 data-[state=checked]:bg-primary data-[state=unchecked]:bg-muted-foreground/30"
                                                                                />
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            );
                                                        });
                                                    })()}
                                                </div>
                                            </div>

                                        <div className="space-y-4 pt-4 border-t border-border/40">
                                            <div className="flex flex-col gap-4">
                                                <div className="flex items-center justify-between">
                                                    <Label className="text-sm font-medium flex items-center gap-2">
                                                        Select Documents
                                                        <span className="text-[10px] text-primary/80 font-mono py-0.5 px-1.5 rounded-md bg-primary/10">{s.documents.length} sel</span>
                                                    </Label>
                                                    <div className="flex flex-wrap items-center gap-2 sm:gap-4">
                                                        <Input 
                                                            placeholder="Search docs..." 
                                                            className="h-7 text-[11px] w-full sm:w-40 rounded-lg bg-card/30"
                                                            value={docSearch}
                                                            onChange={(e) => setDocSearch(e.target.value)}
                                                        />
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-6 text-[10px] px-2 text-muted-foreground hover:text-primary uppercase tracking-wider font-semibold"
                                                            onClick={() => {
                                                                const filtered = documentTypes.filter(d => 
                                                                    d.title.toLowerCase().includes(docSearch.toLowerCase())
                                                                );
                                                                const available = filtered.filter(d => !otherDocuments.has(String(d.id)));
                                                                
                                                                const currentlySelectedInDocSearch = s.documents.filter(sd => 
                                                                    available.some(a => String(a.id) === String(sd.type))
                                                                );

                                                                if (currentlySelectedInDocSearch.length === available.length && available.length > 0) {
                                                                    // Deselect all in search
                                                                    const remaining = s.documents.filter(sd => 
                                                                        !available.some(a => String(a.id) === String(sd.type))
                                                                    );
                                                                    updateStage({ documents: remaining });
                                                                } else {
                                                                    // Select all in search
                                                                    const toAdd = available
                                                                        .filter(a => !s.documents.some(sd => String(sd.type) === String(a.id)))
                                                                        .map(a => ({ type: String(a.id), min: 1, ask_issue_date: true, ask_expiry_date: true, ask_document_number: true }));
                                                                    updateStage({ documents: [...s.documents, ...toAdd] });
                                                                }
                                                            }}
                                                        >
                                                            Select All
                                                        </Button>
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 p-3 rounded-xl border border-border/50 bg-card/30 max-h-[160px] overflow-y-auto">
                                                    {(() => {
                                                        const visible = documentTypes
                                                            .filter((d) =>
                                                                d.title
                                                                    .toLowerCase()
                                                                    .includes(docSearch.toLowerCase())
                                                            )
                                                            .filter(
                                                                (d) =>
                                                                    !otherDocuments.has(String(d.id)) ||
                                                                    s.documents.some(
                                                                        (sd) => String(sd.type) === String(d.id)
                                                                    )
                                                            );

                                                        if (visible.length === 0) {
                                                            return (
                                                                <div className="py-8 text-center text-xs text-muted-foreground sm:col-span-2 lg:col-span-3">
                                                                    No documents found.
                                                                </div>
                                                            );
                                                        }

                                                        return visible.map((d) => {
                                                            const isSelected = s.documents.some(
                                                                (sd) => String(sd.type) === String(d.id)
                                                            );

                                                            return (
                                                                <label
                                                                    key={d.id}
                                                                    className={`flex items-center gap-2.5 p-2 rounded-lg cursor-pointer transition-colors border ${
                                                                        isSelected
                                                                            ? 'bg-primary/5 border-primary/30 text-primary'
                                                                            : 'hover:bg-muted/40 border-transparent text-muted-foreground'
                                                                    }`}
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        className="rounded border-border/50 text-primary w-3.5 h-3.5"
                                                                        checked={isSelected}
                                                                        onChange={(e) => {
                                                                            if (e.target.checked) {
                                                                                updateStage({
                                                                                    documents: [
                                                                                        ...s.documents,
                                                                                        {
                                                                                            type: String(d.id),
                                                                                            min: 1,
                                                                                            ask_issue_date: true,
                                                                                            ask_expiry_date: true,
                                                                                            ask_document_number: true,
                                                                                        },
                                                                                    ],
                                                                                });
                                                                            } else {
                                                                                updateStage({
                                                                                    documents: s.documents.filter(
                                                                                        (sd) => String(sd.type) !== String(d.id)
                                                                                    ),
                                                                                });
                                                                            }
                                                                        }}
                                                                    />
                                                                    <span className="text-xs font-medium truncate">{d.title}</span>
                                                                </label>
                                                            );
                                                        });
                                                    })()}
                                                </div>
                                            </div>

                                            {s.documents.length > 0 && (
                                                <div className="space-y-3 pt-4 border-t border-border/40 mt-4">
                                                    <Label className="text-xs font-medium text-muted-foreground">
                                                        Document rules
                                                    </Label>
                                                    <div className="space-y-2 max-h-[300px] overflow-y-auto pr-1">
                                                        {s.documents.map((d, dIdx) => {
                                                            const typeInfo = documentTypes.find(
                                                                (dt) => String(dt.id) === String(d.type)
                                                            );

                                                            return (
                                                                <div
                                                                    key={`${d.type}-${dIdx}`}
                                                                    className="rounded-xl border border-border/50 bg-card/20 p-4"
                                                                >
                                                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                                        <div className="min-w-0">
                                                                            <div className="truncate text-sm font-semibold">
                                                                                {typeInfo?.title || 'Unknown Doc'}
                                                                            </div>
                                                                        </div>

                                                                        <div className="flex flex-wrap items-center gap-2">
                                                                            <div className="flex items-center gap-2 rounded-lg border border-border/50 bg-background/40 px-2.5 py-1.5">
                                                                                <span className="text-[10px] font-semibold text-muted-foreground">
                                                                                    Min files
                                                                                </span>
                                                                                <input
                                                                                    type="number"
                                                                                    min={0}
                                                                                    value={d.min}
                                                                                    onChange={(e) => {
                                                                                        const v = Number(e.target.value);
                                                                                        const newDocs = [...s.documents];
                                                                                        newDocs[dIdx] = { ...newDocs[dIdx], min: v };
                                                                                        updateStage({ documents: newDocs });
                                                                                    }}
                                                                                    className="w-12 bg-transparent text-xs font-semibold text-foreground focus:outline-none text-center"
                                                                                />
                                                                            </div>

                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                                                                onClick={() => {
                                                                                    updateStage({
                                                                                        documents: s.documents.filter((_, i) => i !== dIdx),
                                                                                    });
                                                                                }}
                                                                            >
                                                                                <Trash2 className="w-4 h-4" />
                                                                            </Button>
                                                                        </div>
                                                                    </div>

                                                                    <div className="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                                                            <span className="text-xs font-medium text-foreground">Issue date</span>
                                                                            <Switch
                                                                                checked={!!d.ask_issue_date}
                                                                                onCheckedChange={(val) => {
                                                                                    const newDocs = [...s.documents];
                                                                                    newDocs[dIdx] = { ...newDocs[dIdx], ask_issue_date: val };
                                                                                    updateStage({ documents: newDocs });
                                                                                }}
                                                                                className="scale-75"
                                                                            />
                                                                        </label>
                                                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                                                            <span className="text-xs font-medium text-foreground">Expiry date</span>
                                                                            <Switch
                                                                                checked={!!d.ask_expiry_date}
                                                                                onCheckedChange={(val) => {
                                                                                    const newDocs = [...s.documents];
                                                                                    newDocs[dIdx] = { ...newDocs[dIdx], ask_expiry_date: val };
                                                                                    updateStage({ documents: newDocs });
                                                                                }}
                                                                                className="scale-75"
                                                                            />
                                                                        </label>
                                                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-background/30 px-3 py-2 cursor-pointer">
                                                                            <span className="text-xs font-medium text-foreground">Doc number</span>
                                                                            <Switch
                                                                                checked={!!d.ask_document_number}
                                                                                onCheckedChange={(val) => {
                                                                                    const newDocs = [...s.documents];
                                                                                    newDocs[dIdx] = { ...newDocs[dIdx], ask_document_number: val };
                                                                                    updateStage({ documents: newDocs });
                                                                                }}
                                                                                className="scale-75"
                                                                            />
                                                                        </label>
                                                                    </div>
                                                            </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })()
                ) : (
                    <div className="glass-card p-6 lg:col-span-9 flex flex-col items-center justify-center text-muted-foreground h-[400px]">
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
