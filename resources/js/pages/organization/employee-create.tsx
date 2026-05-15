import { Head, router, useForm } from '@inertiajs/react';
import { Check, ChevronDown, ChevronLeft, ChevronRight, RotateCcw, Save, UserPlus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Main } from '@/components/layout/main';
import { DocumentRegistry } from '@/components/onboarding/document-registry';
import { FieldRenderer } from '@/components/onboarding/field-renderer';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/lib/toast';

type Option = { id: number | string; name: string; title?: string };

type OnboardingTemplate = {
    id: number;
    name: string;
    description?: string | null;
    tasks: {
        version: number;
        stages: Array<any>;
        modules?: Record<string, any>;
    };
};

type TemplateOption = {
    id: number;
    name: string;
    description?: string | null;
    is_default: boolean;
};

type Props = {
    template: OnboardingTemplate;
    allTemplates: TemplateOption[];
    options: {
        branches: Option[];
        departments: Option[];
        positions: Option[];
        managers: Option[];
        countries: Option[];
        religions: Option[];
        genders: Option[];
        banks: Option[];
        document_types: Array<{ id: number; title: string }>;
    };
};

export default function EmployeeCreate({ template, allTemplates, options }: Props) {
    const normalizeFieldKey = (key: string): string => {
        const legacyMap: Record<string, string> = {
            nationality: 'nationality_id',
            gender: 'gender_id',
            religion: 'religion_id',
            bank: 'bank_id',
            branch: 'branch_id',
            department: 'department_id',
            position: 'position_id',
            manager: 'manager_id',
            first_name: 'name',
            last_name: 'name',
        };

        return legacyMap[key] ?? key;
    };

    const normalizeFieldList = (fields: any[]): Array<{ key: string; required: boolean }> => {
        const seen = new Set<string>();
        const result: Array<{ key: string; required: boolean }> = [];

        for (const f of fields) {
            const key = normalizeFieldKey(typeof f === 'string' ? f : String(f?.key ?? ''));

            if (!key || seen.has(key)) {
continue;
}

            seen.add(key);
            result.push({ key, required: typeof f === 'string' ? true : !!f?.required });
        }

        return result;
    };

    const normalizeStages = (tasks: OnboardingTemplate['tasks']) => {
        if (tasks?.version === 2 && Array.isArray(tasks.stages)) {
            return tasks.stages.map((s: any) => ({
                key: String(s?.key ?? ''),
                label: String(s?.label ?? s?.key ?? ''),
                employee_fields: normalizeFieldList(Array.isArray(s?.employee_fields) ? s.employee_fields : []),
                bank_account_fields: normalizeFieldList(Array.isArray(s?.bank_account_fields) ? s.bank_account_fields : []),
                contract_fields: normalizeFieldList(Array.isArray(s?.contract_fields) ? s.contract_fields : []),
                documents: Array.isArray(s?.documents) ? s.documents : [],
            }));
        }

        if (tasks?.version === 1 && Array.isArray(tasks.stages) && tasks.modules && typeof tasks.modules === 'object') {
            const v1Profile = Array.isArray(tasks.modules?.profile?.required_fields) ? tasks.modules.profile.required_fields : [];
            const v1Contract = Array.isArray(tasks.modules?.contract?.required_fields) ? tasks.modules.contract.required_fields : [];
            const v1Docs = Array.isArray(tasks.modules?.documents?.required_docs) ? tasks.modules.documents.required_docs : [];

            return tasks.stages.map((s: any) => {
                const mods = Array.isArray(s?.modules) ? s.modules : [];
                const bankKeys = new Set(['bank_id', 'iban', 'account_name']);
                const allProfileFields = normalizeFieldList(v1Profile);
                const v1EmployeeFields = allProfileFields.filter((f) => !bankKeys.has(f.key));
                const v1BankFields = allProfileFields.filter((f) => bankKeys.has(f.key));

                return {
                    key: String(s?.key ?? ''),
                    label: String(s?.label ?? s?.key ?? ''),
                    employee_fields: mods.includes('profile') ? v1EmployeeFields : [],
                    bank_account_fields: mods.includes('profile') ? v1BankFields : [],
                    contract_fields: mods.includes('contract') ? normalizeFieldList(v1Contract) : [],
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

    const form = useForm<any>({
        onboarding_template_id: template.id,
        employee_no: '',
        status: 'active',
        name: '',
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
        nationality_id: '',
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
        account_name: '',
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
    const DRAFT_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours
    const isClearingRef = useRef(false);

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

    useEffect(() => {
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const k = localStorage.key(i);

            if (k && k.startsWith('onboarding_draft_') && k !== STORAGE_KEY) {
                localStorage.removeItem(k);
            }
        }

        const saved = localStorage.getItem(STORAGE_KEY);

        if (!saved) {
return;
}

        try {
            const parsed = JSON.parse(saved);
            const age = Date.now() - (parsed?.timestamp ?? 0);

            if (age > DRAFT_TTL_MS) {
                localStorage.removeItem(STORAGE_KEY);

                return;
            }

            if (parsed?.formData) {
                form.setData(parsed.formData);
            }

            if (parsed?.docMetadata && typeof parsed.docMetadata === 'object') {
                setDocUploads(
                    Object.fromEntries(
                        Object.entries(parsed.docMetadata).map(([k, v]: any) => [k, { ...v, files: [] }]),
                    ),
                );
            }

            if (parsed?.formData || parsed?.docMetadata) {
                setIsRestored(true);
                toast.info('Draft restored from local session.');
            }
        } catch {
            localStorage.removeItem(STORAGE_KEY);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (isClearingRef.current) {
            return;
        }

        const docMetadata = Object.fromEntries(
            Object.entries(docUploads).map(([k, v]) => [
                k,
                { issue_date: v.issue_date, expiry_date: v.expiry_date, document_number: v.document_number },
            ]),
        );

        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ formData: form.data, docMetadata, timestamp: Date.now() }),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data, docUploads]);

    const clearDraft = () => {
        isClearingRef.current = true;
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
            if (!f?.required) {
continue;
}

            const key = String(f.key);

            if (key === 'image') {
                if (!form.data.image) {
missingFields.push('Image');
}

                continue;
            }

            if (isEmpty(form.data[key])) {
missingFields.push(labelFromKey(key));
}
        }

        for (const d of (stage.documents ?? [])) {
            const uploaded = docUploads[d.type]?.files?.length ?? 0;

            if (uploaded < Number(d.min ?? 0)) {
                const dt = options.document_types.find((x) => String(x.id) === String(d.type));
                missingFields.push(`${dt?.title ?? 'Document'} (${uploaded}/${d.min})`);
            }
        }

        return missingFields;
    };

    const missingByStageIdx = stages.map((s) => getStageMissing(s));

    const nextStage = () => {
        toast.dismiss();

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
        toast.dismiss();

        if (currentStageIdx > 0) {
            setCurrentStageIdx(currentStageIdx - 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        toast.dismiss();
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

        form.transform((data) => ({ ...data, documents }));
        form.post('/organization/employees', {
            onSuccess: () => {
                localStorage.removeItem(STORAGE_KEY);
                toast.success('Employee created successfully.');
            },
            onError: () => setShowMissingIndicators(true),
        });
    };

    return (
        <Main fixed className="bg-background">
            <Head title={`New Employee — ${activeStage.label}`} />

            <div className="flex flex-col h-full w-full">
                {/* Top Bar */}
                <div className="h-14 border-b border-border bg-background flex items-center justify-between px-5 shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="h-7 w-7 rounded-md bg-primary/10 flex items-center justify-center shrink-0">
                            <UserPlus className="h-3.5 w-3.5 text-primary" />
                        </div>
                        <div>
                            <span className="text-sm font-semibold text-foreground">New Employee</span>
                            {allTemplates.length > 1 ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            className="ml-2 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors"
                                        >
                                            {template.name}
                                            <ChevronDown className="h-3 w-3" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="w-60">
                                        <DropdownMenuLabel className="text-xs text-muted-foreground">Switch template</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        {allTemplates.map((t) => (
                                            <DropdownMenuItem
                                                key={t.id}
                                                onClick={() => router.visit(`/organization/employees/create?template_id=${t.id}`, { replace: true })}
                                                className="flex items-center gap-2 py-2"
                                            >
                                                <span className="flex-1 text-sm">{t.name}</span>
                                                {t.id === template.id && <Check className="h-3.5 w-3.5 text-primary" />}
                                                {t.is_default && t.id !== template.id && (
                                                    <span className="text-xs text-muted-foreground">default</span>
                                                )}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : (
                                <span className="ml-2 text-xs text-muted-foreground">{template.name}</span>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {isRestored && (
                            <Button variant="ghost" size="sm" onClick={clearDraft} className="h-8 gap-1.5 text-xs text-muted-foreground hover:text-destructive">
                                <RotateCcw className="h-3.5 w-3.5" />
                                Reset draft
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.visit('/organization/employees')}
                            className="h-8 text-xs text-muted-foreground hover:text-foreground"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>

                <div className="flex-1 flex overflow-hidden">
                    {/* Steps Sidebar */}
                    <div className="w-56 border-r border-border bg-muted/20 flex flex-col shrink-0">
                        <div className="px-4 py-5 border-b border-border">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-xs font-medium text-muted-foreground">Progress</span>
                                <span className="text-xs font-semibold text-foreground">{currentStageIdx + 1} / {stages.length}</span>
                            </div>
                            <div className="h-1 w-full bg-border rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-primary rounded-full transition-all duration-500"
                                    style={{ width: `${((currentStageIdx + 1) / stages.length) * 100}%` }}
                                />
                            </div>
                        </div>

                        <nav className="flex-1 overflow-y-auto p-3 space-y-0.5">
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
                                        className={`w-full flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-left transition-colors ${
                                            isCurrent
                                                ? 'bg-background border border-border text-foreground shadow-xs'
                                                : canGo
                                                ? 'text-muted-foreground hover:bg-background/60 hover:text-foreground cursor-pointer'
                                                : 'text-muted-foreground/50 cursor-default'
                                        }`}
                                    >
                                        <div className={`h-5 w-5 rounded-full flex items-center justify-center text-[10px] font-semibold shrink-0 ${
                                            isPassed
                                                ? 'bg-primary text-primary-foreground'
                                                : isCurrent
                                                ? 'bg-primary/10 text-primary border border-primary/30'
                                                : 'bg-muted text-muted-foreground border border-border'
                                        }`}>
                                            {isPassed ? <Check className="h-2.5 w-2.5" /> : idx + 1}
                                        </div>
                                        <span className="text-xs font-medium truncate flex-1">{s.label}</span>
                                        {missingCount > 0 && (
                                            <span className="text-[10px] font-semibold bg-destructive/10 text-destructive px-1.5 py-0.5 rounded-md tabular-nums">
                                                {missingCount}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </nav>

                        <div className="px-4 py-3 border-t border-border">
                            <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground/60">
                                <Save className="h-3 w-3" />
                                Saved to session
                            </div>
                        </div>
                    </div>

                    {/* Main Form Area */}
                    <div className="flex-1 flex flex-col overflow-hidden">
                        <div className="flex-1 overflow-y-auto">
                            <div className="px-6 py-6 space-y-8">
                                {/* Stage Header */}
                                <div>
                                    <p className="text-xs font-medium text-muted-foreground mb-1">
                                        Step {currentStageIdx + 1} of {stages.length}
                                    </p>
                                    <h2 className="text-xl font-semibold text-foreground">{activeStage.label}</h2>
                                </div>

                                {/* Employee Fields */}
                                {activeStage.employee_fields.length > 0 && (
                                    <div className="space-y-6">
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
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
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

                                {/* Bank Account Fields */}
                                {activeStage.bank_account_fields.length > 0 && (
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-medium text-foreground">Bank Account</span>
                                            <div className="flex-1 h-px bg-border" />
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
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
                                )}

                                {/* Contract Fields */}
                                {activeStage.contract_fields.length > 0 && (
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-medium text-foreground">Contract</span>
                                            <div className="flex-1 h-px bg-border" />
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
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

                                {/* Documents */}
                                {activeStage.documents.length > 0 && (
                                    <div className="space-y-4">
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-medium text-foreground">Documents</span>
                                            <div className="flex-1 h-px bg-border" />
                                        </div>
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

                        {/* Bottom Navigation */}
                        <div className="border-t border-border bg-background px-6 py-4 flex items-center justify-between shrink-0">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={prevStage}
                                disabled={currentStageIdx === 0}
                                className="h-9 gap-1.5"
                            >
                                <ChevronLeft className="h-4 w-4" />
                                Back
                            </Button>

                            {!isLastStage ? (
                                <Button type="button" onClick={nextStage} className="h-9 gap-1.5">
                                    Continue
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    onClick={submit}
                                    disabled={form.processing}
                                    className="h-9 px-6"
                                >
                                    {form.processing ? 'Saving…' : 'Create Employee'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </Main>
    );
}
