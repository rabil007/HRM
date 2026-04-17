import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DocumentSelector } from '@/components/onboarding/builder/document-selector';
import { FieldSelector } from '@/components/onboarding/builder/field-selector';
import { SidebarStages } from '@/components/onboarding/builder/sidebar-stages';
import type { SortDialogState } from '@/components/onboarding/builder/sort-dialog';
import { SortDialog } from '@/components/onboarding/builder/sort-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/lib/toast';


export const generateId = () => Math.random().toString(36).substring(2, 9);

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
    bank_account_fields: FieldRequirement[];
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
    { key: 'nationality_id', label: 'Nationality' },
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
] as const;

export const bankAccountFieldOptions = [
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
                employee_fields: mapFields(['employee_no', 'first_name', 'last_name', 'work_email', 'phone', 'nationality_id']),
                bank_account_fields: [],
                contract_fields: [],
                documents: []
            },
            { 
                id: generateId(), 
                key: 'contract_docs', 
                label: 'Contract & Documents', 
                employee_fields: [],
                bank_account_fields: [],
                contract_fields: mapFields(['contract_type', 'start_date']),
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
                bank_account_fields: mapFields(s.bank_account_fields),
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

    if (t.version === 1 && Array.isArray(t.stages) && typeof t.modules === 'object') {
        const v1Profile = t.modules?.profile?.required_fields || [];
        const v1Contract = t.modules?.contract?.required_fields || [];
        const v1Docs = t.modules?.documents?.required_docs || [];

        const bankKeys = new Set(['bank_id', 'iban']);
        const v1ProfileEmployee = Array.isArray(v1Profile) ? v1Profile.filter((k: any) => !bankKeys.has(String(k))) : [];
        const v1ProfileBank = Array.isArray(v1Profile) ? v1Profile.filter((k: any) => bankKeys.has(String(k))) : [];

        return {
            stages: t.stages.map((s: any) => ({
                id: generateId(),
                key: String(s.key || '').trim(),
                label: String(s.label || '').trim() || String(s.key || '').trim(),
                employee_fields: Array.isArray(s.modules) && s.modules.includes('profile') ? mapFields(v1ProfileEmployee) : [],
                bank_account_fields: Array.isArray(s.modules) && s.modules.includes('profile') ? mapFields(v1ProfileBank) : [],
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
            bank_account_fields: s.bank_account_fields,
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
    
    const [sortFieldsDialog, setSortFieldsDialog] = useState<SortDialogState>({ 
        open: false, kind: null, list: [], draggingKey: null 
    });

    const sortFieldDialogLabel = (key: string) => {
        const kind = sortFieldsDialog.kind;

        if (kind === 'contract') {
return contractFieldOptions.find((o) => o.key === key)?.label ?? key;
}

        if (kind === 'bank') {
return bankAccountFieldOptions.find((o) => o.key === key)?.label ?? key;
}

        return profileFieldOptions.find((o) => o.key === key)?.label ?? key;
    };

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

    const activeStageIndex = builder.stages.findIndex(s => s.id === activeStageId);
    const s = activeStageIndex !== -1 ? builder.stages[activeStageIndex] : null;

    const updateStage = (data: Partial<StageBuilder>) => {
        if (activeStageIndex === -1) {
return;
}

        setBuilder(prev => {
            const newStages = [...prev.stages];
            newStages[activeStageIndex] = { ...newStages[activeStageIndex], ...data };

            return { stages: newStages };
        });
    };

    const otherStages = builder.stages.filter(x => x.id !== activeStageId);
    const otherProfileFields = new Set(otherStages.flatMap(x => x.employee_fields.map(f => f.key)));
    const otherContractFields = new Set(otherStages.flatMap(x => x.contract_fields.map(f => f.key)));
    const otherBankFields = new Set(otherStages.flatMap(x => x.bank_account_fields.map(f => f.key)));
    const otherDocuments = new Set(otherStages.flatMap(x => x.documents.map(d => String(d.type))));

    return (
        <form onSubmit={submit} className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
            <div className="w-full space-y-8">
                <div className="glass-card p-6 space-y-4">
                    <div className="text-sm font-semibold uppercase tracking-wider text-muted-foreground/70">Basic Information</div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="name">Template Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g., Standard Onboarding" className="h-11 rounded-xl" />
                            {form.errors.name && <div className="text-xs text-destructive">{form.errors.name}</div>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="is_default">Default Template</Label>
                            <div className="h-11 rounded-xl border border-border bg-card/50 px-3 flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Use for new employees</span>
                                <Switch checked={form.data.is_default} onCheckedChange={(v) => form.setData('is_default', v)} />
                            </div>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="description">Description</Label>
                        <Input id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Describe the purpose of this template" className="h-11 rounded-xl" />
                        {form.errors.description && <div className="text-xs text-destructive">{form.errors.description}</div>}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    <SidebarStages builder={builder} setBuilder={setBuilder} activeStageId={activeStageId} setActiveStageId={setActiveStageId} reorderStages={reorderStages} generateId={generateId} />

                    {s ? (
                        <div className="glass-card p-6 space-y-6 lg:col-span-9">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pb-4 border-b border-border/40">
                                <div className="space-y-1">
                                    <h3 className="text-lg font-semibold tracking-tight">{s.label || 'Untitled Stage'}</h3>
                                    <p className="text-xs text-muted-foreground">Configure the properties requested from the user during this stage.</p>
                                </div>
                                <Button type="button" variant="ghost" size="sm" className="text-destructive hover:bg-destructive/10" onClick={() => {
                                    const nextStages = builder.stages.filter(x => x.id !== activeStageId);
                                    setBuilder({ stages: nextStages });
                                    setActiveStageId(nextStages.length > 0 ? nextStages[0].id : null);
                                }}>Delete Stage</Button>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label className="text-xs">Stage Label (Display Name)</Label>
                                    <Input value={s.label} onChange={e => updateStage({ label: e.target.value })} className="h-10 text-sm" />
                                </div>
                                <div className="space-y-2">
                                    <Label className="text-xs text-muted-foreground font-medium flex items-center justify-between">Unique ID (System Key)</Label>
                                    <Input value={s.key} disabled readOnly className="h-10 text-sm bg-muted/30 opacity-70 cursor-not-allowed border-dashed focus-visible:ring-0" />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-border/40">
                                <FieldSelector title="Employee Fields" options={profileFieldOptions} selectedFields={s.employee_fields} otherStagesFields={otherProfileFields} onUpdate={(fields) => updateStage({ employee_fields: fields })} onSortClick={() => setSortFieldsDialog({ open: true, kind: 'employee', list: [...s.employee_fields], draggingKey: null })} />
                                <div className="space-y-6">
                                    <FieldSelector title="Contract Fields" options={contractFieldOptions} selectedFields={s.contract_fields} otherStagesFields={otherContractFields} onUpdate={(fields) => updateStage({ contract_fields: fields })} onSortClick={() => setSortFieldsDialog({ open: true, kind: 'contract', list: [...s.contract_fields], draggingKey: null })} />
                                    <FieldSelector title="Bank Fields" options={bankAccountFieldOptions} selectedFields={s.bank_account_fields} otherStagesFields={otherBankFields} onUpdate={(fields) => updateStage({ bank_account_fields: fields })} onSortClick={() => setSortFieldsDialog({ open: true, kind: 'bank', list: [...s.bank_account_fields], draggingKey: null })} />
                                </div>
                            </div>
                            
                            <DocumentSelector selectedDocs={s.documents} documentTypes={documentTypes} otherStagesDocs={otherDocuments} onUpdate={(docs) => updateStage({ documents: docs })} />
                        </div>
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
                    {onCancel && <Button type="button" variant="ghost" onClick={onCancel} className="w-full md:w-40 h-12 rounded-xl text-muted-foreground hover:text-foreground">Discard Changes</Button>}
                    <Button type="submit" className="w-full md:w-64 h-12 rounded-xl text-lg font-semibold shadow-lg shadow-primary/20" disabled={form.processing}>{template ? 'Update Template' : 'Create Template'}</Button>
                </div>
            </div>

            <SortDialog 
                state={sortFieldsDialog} 
                setState={setSortFieldsDialog} 
                getLabel={sortFieldDialogLabel} 
                onSave={(kind, list) => {
                    updateStage(
                        kind === 'contract' ? { contract_fields: list } :
                        kind === 'bank' ? { bank_account_fields: list } :
                        { employee_fields: list }
                    );
                    setSortFieldsDialog({ open: false, kind: null, list: [], draggingKey: null });
                    toast.success(`${kind === 'contract' ? 'Contract' : kind === 'bank' ? 'Bank' : 'Employee'} fields order updated.`);
                }} 
            />
        </form>
    );
}
