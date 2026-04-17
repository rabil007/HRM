import { Head, router, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, UserPlus, Save, RotateCcw } from 'lucide-react';
import { useState, useEffect } from 'react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { toast } from '@/lib/toast';
import { FieldRenderer } from '@/components/onboarding/field-renderer';
import { DocumentRegistry } from '@/components/onboarding/document-registry';

type Option = { id: number | string; name: string; title?: string };

type OnboardingTemplate = {
    id: number;
    name: string;
    tasks: {
        version: number;
        stages: Array<any>;
        modules?: Record<string, any>;
    };
};

type Props = {
    template: OnboardingTemplate;
    options: {
        branches: Option[];
        departments: Option[];
        positions: Option[];
        managers: Option[];
        countries: Option[];
        religions: Option[];
        genders: Option[];
        banks: Option[];
        document_types: Array<{ id: number; title: string; slug: string }>;
    };
};

export default function EmployeeCreate({ template, options }: Props) {
    const normalizeStages = (tasks: OnboardingTemplate['tasks']) => {
        if (tasks?.version === 2 && Array.isArray(tasks.stages)) {
            return tasks.stages.map((s: any) => ({
                key: String(s?.key ?? ''),
                label: String(s?.label ?? s?.key ?? ''),
                employee_fields: Array.isArray(s?.employee_fields) ? s.employee_fields : [],
                bank_account_fields: Array.isArray(s?.bank_account_fields) ? s.bank_account_fields : [],
                contract_fields: Array.isArray(s?.contract_fields) ? s.contract_fields : [],
                documents: Array.isArray(s?.documents) ? s.documents : [],
            }));
        }

        if (tasks?.version === 1 && Array.isArray(tasks.stages) && tasks.modules && typeof tasks.modules === 'object') {
            const v1Profile = Array.isArray(tasks.modules?.profile?.required_fields) ? tasks.modules.profile.required_fields : [];
            const v1Contract = Array.isArray(tasks.modules?.contract?.required_fields) ? tasks.modules.contract.required_fields : [];
            const v1Docs = Array.isArray(tasks.modules?.documents?.required_docs) ? tasks.modules.documents.required_docs : [];

            return tasks.stages.map((s: any) => {
                const mods = Array.isArray(s?.modules) ? s.modules : [];
                const bankKeys = new Set(['bank_id', 'iban']);
                const v1EmployeeFields = v1Profile.filter((k: any) => !bankKeys.has(String(k))).map((k: any) => ({ key: String(k), required: true }));
                const v1BankFields = v1Profile.filter((k: any) => bankKeys.has(String(k))).map((k: any) => ({ key: String(k), required: true }));

                return {
                    key: String(s?.key ?? ''),
                    label: String(s?.label ?? s?.key ?? ''),
                    employee_fields: mods.includes('profile') ? v1EmployeeFields : [],
                    bank_account_fields: mods.includes('profile') ? v1BankFields : [],
                    contract_fields: mods.includes('contract') ? v1Contract.map((k: any) => ({ key: String(k), required: true })) : [],
                    documents: mods.includes('documents') ? v1Docs : [],
                };
            });
        }
        return [];
    };

    const stages = normalizeStages(template.tasks);
    const [currentStageIdx, setCurrentStageIdx] = useState(0);
    const [showMissingIndicators, setShowMissingIndicators] = useState(false);
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [isRestored, setIsRestored] = useState(false);

    const [docUploads, setDocUploads] = useState<
        Record<
            string,
            {
                files: File[];
                issue_date?: string;
                expiry_date?: string;
                document_number?: string;
            }
        >
    >({});

    const form = useForm<any>({
        employee_no: '',
        status: 'active',
        first_name: '',
        last_name: '',
        work_email: '',
        personal_email: '',
        phone: '',
        phone_home_country: '',
        nearest_airport: '',
        cv_source: '',
        emergency_contact: '',
        emergency_phone: '',
        emergency_contact_home_country: '',
        emergency_phone_home_country: '',
        date_of_birth: '',
        place_of_birth: '',
        gender_id: '',
        religion_id: '',
        nationality: '',
        marital_status: '',
        spouse_name: '',
        spouse_birthdate: '',
        dependent_children_count: '',
        address: '',
        branch_id: '',
        department_id: '',
        position_id: '',
        manager_id: '',
        bank_id: '',
        iban: '',
        passport_number: '',
        emirates_id: '',
        labor_card_number: '',
        image: null,
        contract_type: 'limited',
        start_date: '',
        end_date: '',
        probation_end_date: '',
        labor_contract_id: '',
        basic_salary: '',
        housing_allowance: '',
        transport_allowance: '',
        other_allowances: '',
        documents: [],
    });

    const activeStage = stages[currentStageIdx] ?? stages[0];
    const isLastStage = currentStageIdx === stages.length - 1;

    // --- AUTO-SAVE LOGIC ---
    const STORAGE_KEY = `onboarding_draft_${template.id}`;

    useEffect(() => {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            try {
                const draft = JSON.parse(saved);
                form.setData(draft.formData);
                setDocUploads(Object.fromEntries(
                    Object.entries(draft.docMetadata).map(([k, v]: any) => [k, { ...v, files: [] }])
                ));
                setIsRestored(true);
                toast.info('Draft restored from local session.');
            } catch (e) {
                console.error('Failed to restore draft', e);
            }
        }
    }, []);

    useEffect(() => {
        const docMetadata = Object.fromEntries(
            Object.entries(docUploads).map(([k, v]) => [k, { 
                issue_date: v.issue_date, 
                expiry_date: v.expiry_date, 
                document_number: v.document_number 
            }])
        );
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            formData: form.data,
            docMetadata,
            timestamp: Date.now()
        }));
    }, [form.data, docUploads]);

    const clearDraft = () => {
        localStorage.removeItem(STORAGE_KEY);
        window.location.reload();
    };

    // --- HELPERS ---
    const labelFromKey = (fieldKey: string) => {
        const labelKey = fieldKey.endsWith('_id') ? fieldKey.slice(0, -3) : fieldKey;
        return labelKey.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
    };

    const isEmpty = (value: unknown) => value === null || value === undefined || String(value).trim() === '';

    const getStageMissing = (stage: any) => {
        const missingFields: string[] = [];
        const allFields = [...(stage.employee_fields ?? []), ...(stage.bank_account_fields ?? []), ...(stage.contract_fields ?? [])];

        for (const f of allFields) {
            if (!f?.required) continue;
            const key = String(f.key);
            if (key === 'image') {
                if (!form.data.image) missingFields.push('Image');
                continue;
            }
            if (isEmpty(form.data[key])) missingFields.push(labelFromKey(key));
        }

        for (const d of (stage.documents ?? [])) {
            const uploaded = docUploads[d.type]?.files?.length ?? 0;
            if (uploaded < Number(d.min ?? 0)) {
                const dt = options.document_types.find((x) => String(x.id) === String(d.type) || x.slug === d.type);
                missingFields.push(`${dt?.title ?? 'Document'} (${uploaded}/${d.min})`);
            }
        }
        return missingFields;
    };

    const missingByStageIdx = stages.map((s) => getStageMissing(s));

    const nextStage = () => {
        if (currentStageIdx < stages.length - 1) {
            const missing = missingByStageIdx[currentStageIdx] ?? [];
            if (missing.length > 0) {
                setShowMissingIndicators(true);
                toast.error(`Missing required fields: ${missing.slice(0, 5).join(', ')}`);
            }
            setCurrentStageIdx(currentStageIdx + 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const prevStage = () => {
        if (currentStageIdx > 0) {
            setCurrentStageIdx(currentStageIdx - 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const firstMissingIdx = missingByStageIdx.findIndex((m) => (m?.length ?? 0) > 0);
        if (firstMissingIdx !== -1) {
            setShowMissingIndicators(true);
            toast.error(`Missing required fields in ${stages[firstMissingIdx].label}`);
            return;
        }

        const documents = Object.entries(docUploads)
            .filter(([, v]) => (v.files?.length ?? 0) > 0)
            .map(([type, v]) => ({
                type,
                files: v.files,
                issue_date: v.issue_date || null,
                expiry_date: v.expiry_date || null,
                document_number: v.document_number || null,
            }));

        form.transform((data) => ({ ...data, documents }))
            .post('/organization/employees', {
                onSuccess: () => {
                    localStorage.removeItem(STORAGE_KEY);
                    toast.success('Employee created and onboarding started.');
                },
                onError: () => setShowMissingIndicators(true)
            });
    };

    return (
        <Main fixed className="bg-background">
            <Head title={`Onboarding Pipeline — ${activeStage.label}`} />

            <div className="flex flex-col h-full bg-background w-full">
                {/* Top Bar */}
                <div className="h-16 border-b border-border bg-background flex items-center justify-between px-6 shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="h-8 w-8 rounded bg-primary flex items-center justify-center text-primary-foreground">
                            <UserPlus className="h-4 w-4" />
                        </div>
                        <div className="flex flex-col">
                            <h1 className="text-sm font-bold text-foreground">Launch New Hire</h1>
                            <p className="text-[10px] text-muted-foreground uppercase tracking-tight font-medium">
                                Pipeline: <span className="text-primary">{template.name}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {isRestored && (
                            <Button variant="ghost" size="sm" onClick={clearDraft} className="text-[10px] h-7 gap-1.5 text-muted-foreground hover:text-destructive transition-colors">
                                <RotateCcw className="h-3 w-3" />
                                Reset Draft
                            </Button>
                        )}
                        <div className="h-4 w-[1px] bg-border mx-1" />
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.visit('/organization/employees')}
                            className="text-xs hover:text-destructive"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>

                <div className="flex-1 flex overflow-hidden">
                    {/* Stepper Sidebar */}
                    <div className="w-64 border-r border-border bg-muted/5 flex flex-col shrink-0">
                        <div className="p-6 border-b border-border">
                            <div className="flex items-center justify-between mb-3">
                                <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Flow Progress</span>
                                <span className="text-xs font-black text-primary">{Math.round(((currentStageIdx + 1) / stages.length) * 100)}%</span>
                            </div>
                            <div className="h-1.5 w-full bg-border/50 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-primary shadow-[0_0_10px_rgba(var(--primary),0.5)] transition-all duration-700 ease-out"
                                    style={{ width: `${((currentStageIdx + 1) / stages.length) * 100}%` }}
                                />
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4 space-y-1">
                            {stages.map((s, idx) => {
                                const isCurrent = idx === currentStageIdx;
                                const isPassed = idx < currentStageIdx;
                                const canGo = isPassed || isCurrent;
                                const missingCount = showMissingIndicators ? (missingByStageIdx[idx]?.length ?? 0) : 0;

                                return (
                                    <button
                                        key={s.key}
                                        type="button"
                                        onClick={() => canGo && setCurrentStageIdx(idx)}
                                        className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all duration-200 group ${
                                            isCurrent
                                                ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/20 scale-[1.02]'
                                                : 'text-muted-foreground hover:bg-muted'
                                        }`}
                                    >
                                        <div className={`h-6 w-6 rounded-full border flex items-center justify-center text-[10px] font-bold transition-colors ${
                                            isCurrent ? 'bg-primary-foreground text-primary border-primary-foreground' : 
                                            isPassed ? 'bg-primary/20 border-primary/20 text-primary' : 'border-border'
                                        }`}>
                                            {isPassed ? <Check className="h-3 w-3" /> : idx + 1}
                                        </div>
                                        <span className="text-xs font-bold truncate flex-1">{s.label}</span>
                                        {missingCount > 0 && (
                                            <span className="text-[9px] font-black bg-destructive/10 text-destructive px-1.5 py-0.5 rounded border border-destructive/20">
                                                {missingCount}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                        
                        <div className="p-4 border-t border-border bg-muted/20">
                            <div className="flex items-center gap-2 text-[9px] font-bold text-muted-foreground/60 uppercase tracking-tighter">
                                <Save className="h-3 w-3" />
                                Auto-saved to local session
                            </div>
                        </div>
                    </div>

                    <div className="flex-1 flex flex-col overflow-hidden bg-background">
                        <div className="flex-1 overflow-y-auto p-12">
                            <div className="max-w-6xl mx-auto space-y-12">
                                <div className="space-y-2">
                                    <div className="inline-flex items-center gap-2 px-2 py-1 rounded-md bg-primary/5 border border-primary/10 text-[10px] font-bold text-primary uppercase tracking-widest">
                                        Stage {currentStageIdx + 1} of {stages.length}
                                    </div>
                                    <h2 className="text-4xl font-black tracking-tighter text-foreground italic uppercase">
                                        {activeStage.label}
                                    </h2>
                                    <p className="text-sm text-muted-foreground/80 max-w-xl leading-relaxed">
                                        Please provide the required configuration details for this phase of the onboarding pipeline.
                                    </p>
                                </div>

                                <div className="space-y-16">
                                    {/* Employee Fields */}
                                    {activeStage.employee_fields.length > 0 && (
                                        <div className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
                                            {activeStage.employee_fields.some((f: any) => f.key === 'image') && (
                                                <FieldRenderer
                                                    fieldKey="image"
                                                    isRequired={activeStage.employee_fields.find((f: any) => f.key === 'image')?.required ?? false}
                                                    value={form.data.image}
                                                    onChange={(val) => form.setData('image', val)}
                                                    options={options}
                                                    imagePreview={imagePreview}
                                                    setImagePreview={setImagePreview}
                                                />
                                            )}

                                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-8 gap-y-6">
                                                {activeStage.employee_fields
                                                    .filter((f: any) => f.key !== 'image')
                                                    .map((f: any) => (
                                                        <FieldRenderer
                                                            key={f.key}
                                                            fieldKey={f.key}
                                                            isRequired={f.required}
                                                            value={form.data[f.key]}
                                                            error={form.errors[f.key]}
                                                            onChange={(val) => form.setData(f.key as any, val)}
                                                            options={options}
                                                            formDepartmentId={form.data.department_id}
                                                        />
                                                    ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Bank Accounts */}
                                    {activeStage.bank_account_fields.length > 0 && (
                                        <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500 delay-75">
                                            <div className="text-[10px] font-black uppercase tracking-[0.2em] text-muted-foreground/50 border-b border-border pb-3">Financial Metadata</div>
                                            <div className="bg-muted/10 rounded-3xl p-8 border border-border">
                                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
                                                    {activeStage.bank_account_fields.map((f: any) => (
                                                        <FieldRenderer
                                                            key={f.key}
                                                            fieldKey={f.key}
                                                            isRequired={f.required}
                                                            value={form.data[f.key]}
                                                            error={form.errors[f.key]}
                                                            onChange={(val) => form.setData(f.key as any, val)}
                                                            options={options}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Contract Fields */}
                                    {activeStage.contract_fields.length > 0 && (
                                        <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500 delay-150">
                                            <div className="text-[10px] font-black uppercase tracking-[0.2em] text-muted-foreground/50 border-b border-border pb-3">Governance & Contract</div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-8 gap-y-6">
                                                {activeStage.contract_fields.map((f: any) => (
                                                    <FieldRenderer
                                                        key={f.key}
                                                        fieldKey={f.key}
                                                        isRequired={f.required}
                                                        value={form.data[f.key]}
                                                        error={form.errors[f.key]}
                                                        onChange={(val) => form.setData(f.key as any, val)}
                                                        options={options}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Document Registry */}
                                    {activeStage.documents.length > 0 && (
                                        <div className="animate-in fade-in slide-in-from-bottom-4 duration-500 delay-200">
                                            <DocumentRegistry
                                                documents={activeStage.documents}
                                                docUploads={docUploads}
                                                documentTypes={options.document_types}
                                                onUploadChange={(type, data) => setDocUploads(prev => ({ ...prev, [type]: data }))}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Bottom Navigation */}
                        <div className="h-20 border-t border-border bg-background px-12 flex items-center justify-between shrink-0">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={prevStage}
                                disabled={currentStageIdx === 0}
                                className="h-11 px-8 rounded-xl font-bold uppercase tracking-widest text-[10px] transition-all hover:bg-muted"
                            >
                                <ChevronLeft className="mr-3 h-4 w-4" />
                                Previous Phase
                            </Button>

                            <div className="flex items-center gap-4">
                                {!isLastStage ? (
                                    <Button type="button" onClick={nextStage} className="h-11 px-10 rounded-xl font-bold uppercase tracking-widest text-[10px] shadow-xl shadow-primary/20">
                                        Next Stage
                                        <ChevronRight className="ml-3 h-4 w-4" />
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        onClick={submit}
                                        disabled={form.processing}
                                        className="h-11 px-12 rounded-xl font-bold uppercase tracking-widest text-[10px] shadow-xl shadow-primary/30 animate-pulse-subtle"
                                    >
                                        Complete & Launch
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Main>
    );
}
