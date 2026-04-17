import { Head, router, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/lib/toast';

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
            const v1Profile = Array.isArray(tasks.modules?.profile?.required_fields)
                ? tasks.modules.profile.required_fields
                : [];
            const v1Contract = Array.isArray(tasks.modules?.contract?.required_fields)
                ? tasks.modules.contract.required_fields
                : [];
            const v1Docs = Array.isArray(tasks.modules?.documents?.required_docs)
                ? tasks.modules.documents.required_docs
                : [];

            return tasks.stages.map((s: any) => {
                const mods = Array.isArray(s?.modules) ? s.modules : [];
                const bankKeys = new Set(['bank_id', 'iban']);
                const v1EmployeeFields = v1Profile
                    .filter((k: any) => !bankKeys.has(String(k)))
                    .map((k: any) => ({ key: String(k), required: true }));
                const v1BankFields = v1Profile
                    .filter((k: any) => bankKeys.has(String(k)))
                    .map((k: any) => ({ key: String(k), required: true }));

                return {
                    key: String(s?.key ?? ''),
                    label: String(s?.label ?? s?.key ?? ''),
                    employee_fields: mods.includes('profile')
                        ? v1EmployeeFields
                        : [],
                    bank_account_fields: mods.includes('profile') ? v1BankFields : [],
                    contract_fields: mods.includes('contract')
                        ? v1Contract.map((k: any) => ({ key: String(k), required: true }))
                        : [],
                    documents: mods.includes('documents') ? v1Docs : [],
                };
            });
        }

        return [];
    };

    const stages = normalizeStages(template.tasks);
    const [currentStageIdx, setCurrentStageIdx] = useState(0);
    const activeStage = stages[currentStageIdx] ?? stages[0] ?? {
        key: 'draft',
        label: 'Draft',
        employee_fields: [],
        bank_account_fields: [],
        contract_fields: [],
        documents: [],
    };

    const [docSearch, setDocSearch] = useState('');
    const [imagePreview, setImagePreview] = useState<string | null>(null);
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
        // Profile Fields
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

        // Contract Fields
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

    const isFirstStage = currentStageIdx === 0;
    const isLastStage = currentStageIdx === stages.length - 1;

    const nextStage = () => {
        if (!isLastStage) {
            setDocSearch('');
            setCurrentStageIdx(currentStageIdx + 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const prevStage = () => {
        if (!isFirstStage) {
            setDocSearch('');
            setCurrentStageIdx(currentStageIdx - 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const documents = Object.entries(docUploads)
            .filter(([, v]) => (v.files?.length ?? 0) > 0)
            .map(([type, v]) => ({
                type,
                files: v.files,
                issue_date: v.issue_date || null,
                expiry_date: v.expiry_date || null,
                document_number: v.document_number || null,
            }));

        form.transform((data) => ({
            ...data,
            documents,
        }));

        form.post('/organization/employees', {
            onSuccess: () => {
                toast.success('Employee created and onboarding started.');
            },
        });
    };

    const renderField = (fieldKey: string, isRequired: boolean) => {
        const labelKey = fieldKey.endsWith('_id') ? fieldKey.slice(0, -3) : fieldKey;
        const label = labelKey.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
        const id = fieldKey;
        const selectClass =
            'w-full rounded-lg border border-input bg-background h-10 px-3 text-sm outline-none focus:ring-1 focus:ring-primary transition-all';
        const inputClass =
            'h-10 rounded-lg bg-background border-input focus:ring-1 focus:ring-primary transition-all';

        if (fieldKey === 'image') {
            return (
                <div key={id} className="space-y-2">
                    <Label className="text-xs font-medium text-foreground">
                        Image {isRequired && <span className="text-destructive">*</span>}
                    </Label>

                    <div className="rounded-xl border border-border bg-card/30 p-4">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
                            <div className="h-28 w-28 rounded-xl border border-border bg-muted/20 overflow-hidden flex items-center justify-center">
                                {imagePreview ? (
                                    <img
                                        src={imagePreview}
                                        alt="Employee image preview"
                                        className="h-full w-full object-cover"
                                    />
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
                                        form.setData('image', file);
                                        setImagePreview(file ? URL.createObjectURL(file) : null);
                                    }}
                                    className="block w-full text-sm text-muted-foreground file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                />
                                <div className="text-[11px] text-muted-foreground">PNG/JPG up to 4MB.</div>
                                {form.errors.image && (
                                    <p className="text-[10px] text-destructive">{form.errors.image}</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        // Special handling for dropdowns
        if (fieldKey === 'branch_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Branch</option>
                        {options.branches.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'department_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Department</option>
                        {options.departments.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'position_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Position</option>
                        {options.positions
                            .filter(
                                (p) =>
                                    !form.data.department_id ||
                                    String((p as any).department_id) === String(form.data.department_id),
                            )
                            .map((o) => (
                                <option key={o.id} value={String(o.id)}>
                                    {o.title || o.name}
                                </option>
                            ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'manager_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Manager</option>
                        {options.managers.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {(o as any).first_name} {(o as any).last_name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'gender_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Gender</option>
                        {options.genders.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'religion_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Religion</option>
                        {options.religions.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'nationality') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey] ?? '')}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Nationality</option>
                        {options.countries.map((o) => (
                            <option key={o.id} value={o.name}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'bank_id') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Bank</option>
                        {options.banks.map((o) => (
                            <option key={o.id} value={String(o.id)}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'contract_type') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="limited">Limited</option>
                        <option value="unlimited">Unlimited</option>
                        <option value="part_time">Part Time</option>
                        <option value="contract">Contract</option>
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        if (fieldKey === 'marital_status') {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <select
                        id={id}
                        value={String(form.data[fieldKey])}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={selectClass}
                        required={isRequired}
                    >
                        <option value="">Select Status</option>
                        <option value="single">Single</option>
                        <option value="married">Married</option>
                        <option value="divorced">Divorced</option>
                        <option value="widowed">Widowed</option>
                    </select>
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        // Date fields
        if (fieldKey.includes('date') || fieldKey.includes('birthdate')) {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <Input
                        type="date"
                        id={id}
                        value={form.data[fieldKey]}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={inputClass}
                        required={isRequired}
                    />
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        // Numeric fields
        if (fieldKey.includes('salary') || fieldKey.includes('allowance') || fieldKey.includes('count')) {
            return (
                <div key={id} className="space-y-1.5">
                    <Label htmlFor={id} className="text-xs font-medium text-foreground">
                        {label} {isRequired && <span className="text-destructive">*</span>}
                    </Label>
                    <Input
                        type="number"
                        id={id}
                        placeholder={label}
                        value={form.data[fieldKey]}
                        onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                        className={inputClass}
                        required={isRequired}
                    />
                    {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
                </div>
            );
        }

        // Default text input
        return (
            <div key={id} className="space-y-1.5">
                <Label htmlFor={id} className="text-xs font-medium text-foreground">
                    {label} {isRequired && <span className="text-destructive">*</span>}
                </Label>
                <Input
                    id={id}
                    placeholder={label}
                    value={form.data[fieldKey]}
                    onChange={(e) => form.setData(fieldKey as any, e.target.value)}
                    className={inputClass}
                    required={isRequired}
                />
                {form.errors[fieldKey] && <p className="text-[10px] text-destructive">{form.errors[fieldKey]}</p>}
            </div>
        );
    };

    return (
        <Main fixed className="bg-background">
            <Head title={`Onboarding Pipeline — ${activeStage.label}`} />

            <div className="flex flex-col h-full bg-background border-r border-border w-full">
                {/* Simple Top Bar */}
                <div className="h-16 border-b border-border bg-background flex items-center justify-between px-6 shrink-0">
                    <div className="flex items-center gap-3">
                        <div className="h-8 w-8 rounded bg-primary flex items-center justify-center text-primary-foreground">
                            <UserPlus className="h-4 w-4" />
                        </div>
                        <div className="flex flex-col">
                            <h1 className="text-sm font-bold text-foreground">Launch New Hire</h1>
                            <p className="text-[10px] text-muted-foreground">
                                Pipeline: <span className="font-medium">{template.name}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
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
                    {/* Basic Stepper */}
                    <div className="w-64 border-r border-border bg-muted/20 flex flex-col shrink-0">
                        <div className="p-4 border-b border-border">
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Progress</span>
                                <span className="text-xs font-bold">{Math.round(((currentStageIdx + 1) / stages.length) * 100)}%</span>
                            </div>
                            <div className="h-1 w-full bg-border rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-primary transition-all duration-500"
                                    style={{ width: `${((currentStageIdx + 1) / stages.length) * 100}%` }}
                                />
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4 space-y-1">
                            {stages.map((s, idx) => {
                                const isCurrent = idx === currentStageIdx;
                                const isPassed = idx < currentStageIdx;
                                const canGo = isPassed;

                                return (
                                    <button
                                        key={s.key}
                                        type="button"
                                        onClick={() => canGo && setCurrentStageIdx(idx)}
                                        disabled={!canGo}
                                        className={`w-full flex items-center gap-3 px-3 py-2 rounded-md text-left transition-colors ${
                                            isCurrent
                                                ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                                                : 'text-muted-foreground hover:text-foreground hover:bg-background/50'
                                        } ${!canGo && !isCurrent ? 'opacity-50 cursor-not-allowed' : ''}`}
                                    >
                                        <div className={`h-5 w-5 rounded-full border flex items-center justify-center text-[10px] font-bold ${
                                            isPassed ? 'bg-primary border-primary text-primary-foreground' : 
                                            isCurrent ? 'border-primary text-primary' : 'border-muted-foreground'
                                        }`}>
                                            {isPassed ? <Check className="h-3 w-3" /> : idx + 1}
                                        </div>
                                        <span className="text-xs font-medium truncate">{s.label}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex-1 flex flex-col overflow-hidden bg-background">
                        <div className="flex-1 overflow-y-auto p-12">
                            <div className="w-full space-y-8 pb-20">
                                <div className="space-y-1">
                                    <h2 className="text-2xl font-bold tracking-tight text-foreground">
                                            {activeStage.label}
                                        </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Please provide the following details for this stage.
                                        </p>
                                </div>

                                <form onSubmit={submit} className="space-y-8">
                                    <div className="space-y-10">
                                        {/* Employee Fields */}
                                        {activeStage.employee_fields.length > 0 && (
                                            <div className="space-y-6">
                                                {activeStage.employee_fields.some((f) => f.key === 'image') && (
                                                    <div>
                                                        {renderField(
                                                            'image',
                                                            activeStage.employee_fields.find((f) => f.key === 'image')
                                                                ?.required ?? false,
                                                        )}
                                                    </div>
                                                )}

                                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                                    {activeStage.employee_fields
                                                        .filter((f) => f.key !== 'image')
                                                        .map((f) => renderField(f.key, f.required))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Bank Accounts */}
                                        {activeStage.bank_account_fields.length > 0 && (
                                            <div className="space-y-6">
                                                <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b pb-2">
                                                    Bank accounts
                                                </div>
                                                <div className="rounded-xl border border-border bg-card/30 p-6">
                                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                                        {activeStage.bank_account_fields.map((f) =>
                                                            renderField(f.key, f.required)
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* Contract Fields */}
                                        {activeStage.contract_fields.length > 0 && (
                                            <div className="space-y-6">
                                                <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b pb-2">Contract Details</div>
                                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                                    {activeStage.contract_fields.map((f) => renderField(f.key, f.required))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Document Table */}
                                        {activeStage.documents.length > 0 && (
                                            <div className="space-y-4">
                                                <div className="flex items-center justify-between border-b pb-2">
                                                    <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Required Documents</div>
                                                    <div className="relative w-64">
                                                        <Input 
                                                            placeholder="Search documents..."
                                                            value={docSearch}
                                                            onChange={(e) => setDocSearch(e.target.value)}
                                                            className="h-8 text-xs pl-3 pr-8 rounded-md bg-muted/30 border-none focus-visible:ring-1 focus-visible:ring-primary"
                                                        />
                                                        {docSearch && (
                                                            <button 
                                                                type="button"
                                                                onClick={() => setDocSearch('')}
                                                                className="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-muted-foreground hover:text-foreground"
                                                            >
                                                                Clear
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="overflow-x-auto">
                                                    <table className="w-full text-left border-collapse">
                                                        <thead>
                                                            <tr className="border-b border-border bg-muted/50">
                                                                <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground w-1/4">Document Type</th>
                                                                <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Requirements</th>
                                                                <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Upload</th>
                                                                <th className="px-4 py-3 text-[10px] font-bold uppercase text-muted-foreground">Doc Details</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-border">
                                                            {activeStage.documents
                                                                .filter(d => {
                                                                    const docTitle = options.document_types.find(dt => String(dt.slug) === String(d.type) || String(dt.id) === String(d.type))?.title || d.type;

                                                                    return docTitle.toLowerCase().includes(docSearch.toLowerCase());
                                                                })
                                                                .map((d) => {
                                                                    const docTitle =
                                                                        options.document_types.find(
                                                                            (dt) =>
                                                                                String(dt.slug) === String(d.type) ||
                                                                                String(dt.id) === String(d.type),
                                                                        )?.title ||
                                                                        d.type.split('_').join(' ').toUpperCase();

                                                                    const value = docUploads[d.type] ?? { files: [] };
                                                                    const selectedCount = value.files?.length ?? 0;

                                                                    return (
                                                                        <tr key={d.type} className="group hover:bg-muted/30 transition-colors">
                                                                            <td className="px-4 py-4">
                                                                                <span className="text-sm font-bold text-foreground block">
                                                                                    {docTitle}
                                                                </span>
                                                                            </td>
                                                                            <td className="px-4 py-4">
                                                                                <span className="text-[10px] font-medium bg-muted border px-2 py-0.5 rounded-full inline-flex items-center">
                                                                                    Min {d.min}
                                                                </span>
                                                                            </td>
                                                                            <td className="px-4 py-4">
                                                                                <div className="flex flex-col gap-1">
                                                                                    <label className="text-[10px] font-bold uppercase bg-background border px-3 py-1.5 rounded-md cursor-pointer hover:bg-muted transition-colors inline-block w-fit text-center">
                                                                                        <input
                                                                                            type="file"
                                                                                            multiple
                                                                                            className="hidden"
                                                                                            onChange={(e) => {
                                                                                                const files = Array.from(e.target.files ?? []);
                                                                                                setDocUploads((prev) => ({
                                                                                                    ...prev,
                                                                                                    [d.type]: { ...(prev[d.type] ?? { files: [] }), files },
                                                                                                }));
                                                                                            }}
                                                                                        />
                                                                                        {selectedCount > 0 ? `${selectedCount} Files` : 'Select File'}
                                                                                    </label>
                                                                                    {selectedCount > 0 && (
                                                                                        <button 
                                                                                            type="button"
                                                                                            onClick={() => setDocUploads(prev => ({ ...prev, [d.type]: { ...prev[d.type], files: [] } }))}
                                                                                            className="text-[9px] text-destructive hover:underline text-left pl-1"
                                                                                        >
                                                                                            Clear
                                                                                        </button>
                                                                                    )}
                                                                                </div>
                                                                            </td>
                                                                            <td className="px-4 py-4">
                                                                                <div className="flex items-center gap-3">
                                                                                    {d.ask_issue_date && (
                                                                                        <div className="flex flex-col gap-1 w-32">
                                                                                            <label className="text-[9px] font-bold uppercase text-muted-foreground/70">Issue Date</label>
                                                                                            <Input
                                                                                                type="date"
                                                                                                value={value.issue_date ?? ''}
                                                                                                onChange={(e) => {
                                                                                                    const issue_date = e.target.value;
                                                                                                    setDocUploads((prev) => ({
                                                                                                        ...prev,
                                                                                                        [d.type]: { ...(prev[d.type] ?? { files: [] }), issue_date },
                                                                                                    }));
                                                                                                }}
                                                                                                className="h-8 text-[10px] px-2"
                                                                                            />
                                                                                        </div>
                                                                                    )}
                                                                                    {d.ask_expiry_date && (
                                                                                        <div className="flex flex-col gap-1 w-32">
                                                                                            <label className="text-[9px] font-bold uppercase text-muted-foreground/70">Expiry Date</label>
                                                                                            <Input
                                                                                                type="date"
                                                                                                value={value.expiry_date ?? ''}
                                                                                                onChange={(e) => {
                                                                                                    const expiry_date = e.target.value;
                                                                                                    setDocUploads((prev) => ({
                                                                                                        ...prev,
                                                                                                        [d.type]: { ...(prev[d.type] ?? { files: [] }), expiry_date },
                                                                                                    }));
                                                                                                }}
                                                                                                className="h-8 text-[10px] px-2"
                                                                                            />
                                                            </div>
                                                                                    )}
                                                                                    {d.ask_document_number && (
                                                                                        <div className="flex flex-col gap-1 w-32">
                                                                                            <label className="text-[9px] font-bold uppercase text-muted-foreground/70">Number</label>
                                                                                            <Input
                                                                                                value={value.document_number ?? ''}
                                                                                                onChange={(e) => {
                                                                                                    const document_number = e.target.value;
                                                                                                    setDocUploads((prev) => ({
                                                                                                        ...prev,
                                                                                                        [d.type]: { ...(prev[d.type] ?? { files: [] }), document_number },
                                                                                                    }));
                                                                                                }}
                                                                                                className="h-8 text-[10px] px-2"
                                                                                                placeholder="Doc #"
                                                                                            />
                                                            </div>
                                                                                    )}
                                                                                    {!d.ask_issue_date && !d.ask_expiry_date && !d.ask_document_number && (
                                                                                        <span className="text-[10px] text-muted-foreground/40 italic">No additional details</span>
                                                                                    )}
                                                        </div>
                                                                            </td>
                                                                        </tr>
                                                                    );
                                                                })}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </form>
                            </div>
                        </div>

                        {/* Bottom Navigation */}
                        <div className="h-16 border-t border-border bg-background px-8 flex items-center justify-between shrink-0">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={prevStage}
                                disabled={isFirstStage}
                                className="h-9 text-xs px-4"
                            >
                                <ChevronLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>

                            <div className="flex items-center gap-2">
                                {!isLastStage ? (
                                    <Button type="button" onClick={nextStage} className="h-9 text-xs px-6">
                                        Next
                                        <ChevronRight className="ml-2 h-4 w-4" />
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={form.processing}
                                        className="h-9 text-xs px-6"
                            >
                                        Complete Onboarding
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
