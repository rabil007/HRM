import { useForm } from '@inertiajs/react';
import {
    FileStack,
    Info,
    Landmark,
    LayoutTemplate,
    Receipt,
    Ship,
    Syringe,
    UserRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DocumentSelector } from '@/components/onboarding/builder/document-selector';
import { FieldSelector } from '@/components/onboarding/builder/field-selector';
import { SidebarStages } from '@/components/onboarding/builder/sidebar-stages';
import type { SortDialogState } from '@/components/onboarding/builder/sort-dialog';
import { SortDialog } from '@/components/onboarding/builder/sort-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';


export const generateId = () => Math.random().toString(36).substring(2, 9);

const stageBuilderId = (key: string, index: number): string => {
    const trimmed = key.trim();

    return trimmed !== '' ? trimmed : `stage-${index}`;
};

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
    sea_service_fields: FieldRequirement[];
    vaccination_fields: FieldRequirement[];
    documents: DocsRequirement[];
};

export type BuilderState = {
    stages: StageBuilder[];
};

export const profileFieldOptions = [
    { key: 'employee_no', label: 'Employee No' },
    { key: 'name', label: 'Name' },
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
    { key: 'passport_number', label: 'Passport Number' },
    { key: 'emirates_id', label: 'Emirates ID' },
    { key: 'labor_card_number', label: 'Labor Card Number' },
    { key: 'branch_id', label: 'Branch' },
    { key: 'department_id', label: 'Department' },
    { key: 'position_id', label: 'Position' },
    { key: 'rank_id', label: 'Rank' },
    { key: 'manager_id', label: 'Manager' },
] as const;

export const bankAccountFieldOptions = [
    { key: 'bank_id', label: 'Bank' },
    { key: 'iban', label: 'IBAN' },
    { key: 'account_name', label: 'Account Name' },
] as const;

export const contractFieldOptions = [
    { key: 'contract_type', label: 'Contract Type' },
    { key: 'start_date', label: 'Start Date' },
    { key: 'end_date', label: 'End Date' },
    { key: 'labor_contract_id', label: 'Labor Contract ID' },
    { key: 'basic_salary', label: 'Basic Salary' },
    { key: 'housing_allowance', label: 'Housing Allowance' },
    { key: 'transport_allowance', label: 'Transport Allowance' },
    { key: 'other_allowances', label: 'Other Allowances' },
] as const;

export const seaServiceFieldOptions = [
    { key: 'vessel_type_id', label: 'Vessel type' },
    { key: 'vessel_name', label: 'Vessel name' },
    { key: 'rank_id', label: 'Rank' },
    { key: 'total_months', label: 'Total months' },
    { key: 'total_days', label: 'Total days' },
    { key: 'grt', label: 'GRT' },
    { key: 'bhp', label: 'BHP' },
    { key: 'client_id', label: 'Client' },
    { key: 'is_offshore', label: 'Offshore' },
] as const;

export const vaccinationFieldOptions = [
    { key: 'vaccination_name', label: 'Vaccination name' },
    { key: 'country_id', label: 'Country' },
    { key: 'first_dose_date', label: 'First dose date' },
    { key: 'second_dose_date', label: 'Second dose date' },
    { key: 'booster_dose_date', label: 'Booster dose date' },
] as const;

export type DocumentTypeModel = {
    id: number;
    title: string;
};

export function toBuilderState(tasks: unknown): BuilderState {
    const normalizeFieldKey = (key: string) => {
        if (key === 'first_name' || key === 'last_name') {
            return 'name';
        }

        if (key === 'vessel_id') {
            return 'vessel_type_id';
        }

        return key;
    };

    const mapFields = (fields: any): FieldRequirement[] => {
        if (!Array.isArray(fields)) {
return [];
}

        const seen = new Set<string>();

        return fields.map(f => {
            if (typeof f === 'string') {
return { key: normalizeFieldKey(f), required: true };
}

            if (typeof f === 'object' && f !== null) {
return { key: normalizeFieldKey(String(f.key ?? '')), required: !!f.required };
}

            return { key: '', required: true };
        }).filter((f) => {
            if (f.key === '' || seen.has(f.key)) {
return false;
}

            seen.add(f.key);

            return true;
        });
    };

    const fallback: BuilderState = {
        stages: [
            { 
                id: stageBuilderId('profile_info', 0), 
                key: 'profile_info', 
                label: 'Profile Information', 
                employee_fields: mapFields(['employee_no', 'name', 'work_email', 'phone', 'nationality_id']),
                bank_account_fields: [],
                contract_fields: [],
                sea_service_fields: [],
                vaccination_fields: [],
                documents: []
            },
            { 
                id: stageBuilderId('contract_docs', 1), 
                key: 'contract_docs', 
                label: 'Contract & Documents', 
                employee_fields: [],
                bank_account_fields: [],
                contract_fields: mapFields(['contract_type', 'start_date']),
                sea_service_fields: [],
                vaccination_fields: [],
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
            stages: t.stages.map((s: any, index: number) => {
                const key = String(s.key || '').trim();

                return {
                id: stageBuilderId(key, index),
                key,
                label: String(s.label || '').trim() || key,
                employee_fields: mapFields(s.employee_fields),
                bank_account_fields: mapFields(s.bank_account_fields),
                contract_fields: mapFields(s.contract_fields),
                sea_service_fields: mapFields(s.sea_service_fields),
                vaccination_fields: mapFields(s.vaccination_fields),
                documents: Array.isArray(s.documents)
                    ? s.documents.map((d: any) => ({
                          type: String(d?.type ?? ''),
                          min: Number(d?.min ?? 1),
                          ask_issue_date: !!d?.ask_issue_date,
                          ask_expiry_date: !!d?.ask_expiry_date,
                          ask_document_number: !!d?.ask_document_number,
                      }))
                    : [],
            };
            }),
        };
    }

    return fallback;
}

function otherStageFieldUsage(
    stages: StageBuilder[],
    activeStageId: string | null,
    pick: (stage: StageBuilder) => FieldRequirement[],
): { fields: Set<string>; labels: Map<string, string> } {
    const fields = new Set<string>();
    const labels = new Map<string, string>();

    for (const stage of stages) {
        if (stage.id === activeStageId) {
            continue;
        }

        const stepLabel = stage.label || stage.key;

        for (const field of pick(stage)) {
            if (field.key === '') {
                continue;
            }

            fields.add(field.key);
            labels.set(field.key, stepLabel);
        }
    }

    return { fields, labels };
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
            sea_service_fields: s.sea_service_fields,
            vaccination_fields: s.vaccination_fields,
            documents: s.documents
                .filter((d) => String(d.type).trim() !== '')
                .map((d) => ({
                    type: d.type,
                    min: d.min,
                    ask_issue_date: !!d.ask_issue_date,
                    ask_expiry_date: !!d.ask_expiry_date,
                    ask_document_number: !!d.ask_document_number,
                })),
        })),
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

    const [configureTab, setConfigureTab] = useState('basics');
    const [deleteStageOpen, setDeleteStageOpen] = useState(false);

    const [sortFieldsDialog, setSortFieldsDialog] = useState<SortDialogState>({
        open: false,
        kind: null,
        list: [],
        draggingKey: null,
    });

    const sortFieldDialogLabel = (key: string) => {
        const kind = sortFieldsDialog.kind;

        if (kind === 'contract') {
return contractFieldOptions.find((o) => o.key === key)?.label ?? key;
}

        if (kind === 'bank') {
return bankAccountFieldOptions.find((o) => o.key === key)?.label ?? key;
}

        if (kind === 'sea_service') {
return seaServiceFieldOptions.find((o) => o.key === key)?.label ?? key;
}

        if (kind === 'vaccination') {
return vaccinationFieldOptions.find((o) => o.key === key)?.label ?? key;
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
        tasks_json: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            tasks_json: JSON.stringify(buildTasksFromBuilder(builder)),
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

    const otherDocuments = useMemo(
        () =>
            new Set(
                builder.stages
                    .filter((x) => x.id !== activeStageId)
                    .flatMap((x) => x.documents.map((d) => String(d.type))),
            ),
        [builder.stages, activeStageId],
    );

    const otherEmployeeFields = useMemo(
        () => otherStageFieldUsage(builder.stages, activeStageId, (stage) => stage.employee_fields),
        [builder.stages, activeStageId],
    );

    const otherContractFields = useMemo(
        () => otherStageFieldUsage(builder.stages, activeStageId, (stage) => stage.contract_fields),
        [builder.stages, activeStageId],
    );

    const otherBankFields = useMemo(
        () => otherStageFieldUsage(builder.stages, activeStageId, (stage) => stage.bank_account_fields),
        [builder.stages, activeStageId],
    );

    const otherSeaFields = useMemo(
        () => otherStageFieldUsage(builder.stages, activeStageId, (stage) => stage.sea_service_fields),
        [builder.stages, activeStageId],
    );

    const otherVacFields = useMemo(
        () => otherStageFieldUsage(builder.stages, activeStageId, (stage) => stage.vaccination_fields),
        [builder.stages, activeStageId],
    );

    return (
        <form onSubmit={submit} className="animate-in fade-in slide-in-from-bottom-4 duration-300">
            <div className="w-full space-y-8">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Template details</CardTitle>
                        <CardDescription>
                            Visible when assigning onboarding. Rank works like gender—a field on the employee when you
                            include it in steps—not a filter for which template is used.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="e.g. Office staff — UAE"
                                    className="h-11 rounded-lg text-base md:text-sm"
                                />
                                {form.errors.name ? (
                                    <p className="text-xs text-destructive">{form.errors.name}</p>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <div className="flex h-full min-h-[4.5rem] flex-col justify-center rounded-xl border border-border bg-muted/20 px-4 py-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="min-w-0 space-y-0.5">
                                            <Label htmlFor="is_default" className="text-foreground">
                                                Default template
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Used when creating a new hire if no other template is chosen.
                                            </p>
                                        </div>
                                        <Switch
                                            id="is_default"
                                            checked={form.data.is_default}
                                            onCheckedChange={(v) => form.setData('is_default', v)}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                rows={3}
                                value={form.data.description ?? ''}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="When to use this template (e.g. crew joining in Dubai)."
                                className={cn(
                                    'border-input placeholder:text-muted-foreground flex min-h-[88px] w-full resize-y rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm',
                                    'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                                )}
                            />
                            {form.errors.description ? (
                                <p className="text-xs text-destructive">{form.errors.description}</p>
                            ) : null}
                        </div>
                        {form.errors.tasks_json ? (
                            <p className="text-sm text-destructive">{form.errors.tasks_json}</p>
                        ) : null}
                    </CardContent>
                </Card>

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
                    <SidebarStages
                        builder={builder}
                        setBuilder={setBuilder}
                        activeStageId={activeStageId}
                        setActiveStageId={setActiveStageId}
                        reorderStages={reorderStages}
                        generateId={generateId}
                        onStageActivated={() => setConfigureTab('basics')}
                    />

                    {s ? (
                        <Card className="gap-4 py-5 lg:col-span-9">
                            <CardHeader className="pb-0">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="space-y-1">
                                        <CardTitle className="text-xl font-semibold tracking-tight">
                                            {s.label || 'Untitled step'}
                                        </CardTitle>
                                        <CardDescription>
                                            Step{' '}
                                            {Math.max(
                                                1,
                                                builder.stages.findIndex((x) => x.id === activeStageId) + 1,
                                            )}{' '}
                                            of {builder.stages.length}. Use tabs to reduce scrolling.
                                        </CardDescription>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0 gap-2 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => setDeleteStageOpen(true)}
                                    >
                                        Remove step
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4 pt-2">
                                <Alert className="border-primary/25 bg-primary/5">
                                    <Info className="text-primary" />
                                    <AlertTitle className="text-foreground">No duplicate fields across steps</AlertTitle>
                                    <AlertDescription>
                                        A checkbox that is grayed out in another step is already used there. Finished
                                        employees see each step in order in the onboarding wizard.
                                    </AlertDescription>
                                </Alert>

                                <Tabs value={configureTab} onValueChange={setConfigureTab} className="w-full gap-3">
                                    <TabsList className="flex h-auto min-h-10 w-full flex-wrap justify-start gap-1 rounded-xl bg-muted/70 p-1.5 md:p-1">
                                        <TabsTrigger
                                            value="basics"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <LayoutTemplate className="size-3.5 opacity-70" />
                                            Step info
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="employee"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <UserRound className="size-3.5 opacity-70" />
                                            Employee
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.employee_fields.length}
                                            </span>
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="contract"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <Receipt className="size-3.5 opacity-70" />
                                            Contract
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.contract_fields.length}
                                            </span>
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="bank"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <Landmark className="size-3.5 opacity-70" />
                                            Bank
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.bank_account_fields.length}
                                            </span>
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="sea_service"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <Ship className="size-3.5 opacity-70" />
                                            Sea service
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.sea_service_fields.length}
                                            </span>
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="vaccination"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <Syringe className="size-3.5 opacity-70" />
                                            Vaccines
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.vaccination_fields.length}
                                            </span>
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="documents"
                                            className="gap-2 rounded-lg px-3 py-2 text-xs data-[state=active]:shadow-md sm:text-sm"
                                        >
                                            <FileStack className="size-3.5 opacity-70" />
                                            Documents
                                            <span className="ml-0.5 rounded-full bg-primary/15 px-1.5 py-0 text-[10px] font-semibold tabular-nums text-primary">
                                                {s.documents.filter((d) => String(d.type).trim()).length}
                                            </span>
                                        </TabsTrigger>
                                    </TabsList>

                                    <TabsContent value="basics" className="mt-4 space-y-4 focus-visible:outline-none">
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor={`stage-label-${s.id}`}>Step title shown to hires</Label>
                                                <Input
                                                    id={`stage-label-${s.id}`}
                                                    value={s.label}
                                                    onChange={(e) => updateStage({ label: e.target.value })}
                                                    placeholder="e.g. Profile & payroll"
                                                    className="h-11 rounded-lg"
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Shown at the top of this step in onboarding.
                                                </p>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor={`stage-key-${s.id}`}>Technical key</Label>
                                                <Input
                                                    id={`stage-key-${s.id}`}
                                                    value={s.key}
                                                    readOnly
                                                    disabled
                                                    className="h-11 cursor-not-allowed rounded-lg bg-muted/50 font-mono text-xs opacity-90"
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Used internally; stays fixed when editing an existing template.
                                                </p>
                                            </div>
                                        </div>
                                    </TabsContent>

                                    <TabsContent value="employee" className="mt-4 focus-visible:outline-none">
                                        <FieldSelector
                                            title="Profile & assignment fields"
                                            options={profileFieldOptions}
                                            selectedFields={s.employee_fields}
                                            otherStagesFields={otherEmployeeFields.fields}
                                            otherStageLabels={otherEmployeeFields.labels}
                                            onUpdate={(fields) => updateStage({ employee_fields: fields })}
                                            onSortClick={() =>
                                                setSortFieldsDialog({
                                                    open: true,
                                                    kind: 'employee',
                                                    list: [...s.employee_fields],
                                                    draggingKey: null,
                                                })
                                            }
                                        />
                                    </TabsContent>

                                    <TabsContent value="contract" className="mt-4 focus-visible:outline-none">
                                        <FieldSelector
                                            title="Contract"
                                            options={contractFieldOptions}
                                            selectedFields={s.contract_fields}
                                            otherStagesFields={otherContractFields.fields}
                                            otherStageLabels={otherContractFields.labels}
                                            onUpdate={(fields) => updateStage({ contract_fields: fields })}
                                            onSortClick={() =>
                                                setSortFieldsDialog({
                                                    open: true,
                                                    kind: 'contract',
                                                    list: [...s.contract_fields],
                                                    draggingKey: null,
                                                })
                                            }
                                        />
                                    </TabsContent>

                                    <TabsContent value="bank" className="mt-4 focus-visible:outline-none">
                                        <FieldSelector
                                            title="Bank accounts (onboarding form)"
                                            options={bankAccountFieldOptions}
                                            selectedFields={s.bank_account_fields}
                                            otherStagesFields={otherBankFields.fields}
                                            otherStageLabels={otherBankFields.labels}
                                            onUpdate={(fields) => updateStage({ bank_account_fields: fields })}
                                            onSortClick={() =>
                                                setSortFieldsDialog({
                                                    open: true,
                                                    kind: 'bank',
                                                    list: [...s.bank_account_fields],
                                                    draggingKey: null,
                                                })
                                            }
                                        />
                                    </TabsContent>

                                    <TabsContent value="sea_service" className="mt-4 focus-visible:outline-none">
                                        <FieldSelector
                                            title="Sea service (profile after hire)"
                                            options={seaServiceFieldOptions}
                                            selectedFields={s.sea_service_fields}
                                            otherStagesFields={otherSeaFields.fields}
                                            otherStageLabels={otherSeaFields.labels}
                                            onUpdate={(fields) => updateStage({ sea_service_fields: fields })}
                                            onSortClick={() =>
                                                setSortFieldsDialog({
                                                    open: true,
                                                    kind: 'sea_service',
                                                    list: [...s.sea_service_fields],
                                                    draggingKey: null,
                                                })
                                            }
                                        />
                                    </TabsContent>

                                    <TabsContent value="vaccination" className="mt-4 focus-visible:outline-none">
                                        <FieldSelector
                                            title="Vaccinations (profile after hire)"
                                            options={vaccinationFieldOptions}
                                            selectedFields={s.vaccination_fields}
                                            otherStagesFields={otherVacFields.fields}
                                            otherStageLabels={otherVacFields.labels}
                                            onUpdate={(fields) => updateStage({ vaccination_fields: fields })}
                                            onSortClick={() =>
                                                setSortFieldsDialog({
                                                    open: true,
                                                    kind: 'vaccination',
                                                    list: [...s.vaccination_fields],
                                                    draggingKey: null,
                                                })
                                            }
                                        />
                                    </TabsContent>

                                    <TabsContent value="documents" className="mt-4 focus-visible:outline-none">
                                        <DocumentSelector
                                            selectedDocs={s.documents}
                                            documentTypes={documentTypes}
                                            otherStagesDocs={otherDocuments}
                                            onUpdate={(docs) => updateStage({ documents: docs })}
                                        />
                                    </TabsContent>
                                </Tabs>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="flex min-h-[380px] flex-col items-center justify-center gap-3 border-dashed lg:col-span-9">
                            <LayoutTemplate className="size-12 text-muted-foreground/40" />
                            <div className="text-center">
                                <p className="text-sm font-medium text-foreground">Choose a step to edit</p>
                                <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                                    Use the workflow list or add a new step first.
                                </p>
                            </div>
                        </Card>
                    )}
                </div>
            </div>

            <div className="sticky bottom-0 z-30 mt-12 border-t border-border/90 bg-background/95 py-4 shadow-[0_-8px_30px_-12px_rgba(0,0,0,0.15)] backdrop-blur-md supports-[backdrop-filter]:bg-background/90">
                <div className="flex w-full flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    {onCancel ? (
                        <Button type="button" variant="ghost" className="w-full sm:w-auto" onClick={onCancel}>
                            Discard
                        </Button>
                    ) : null}
                    <Button
                        type="submit"
                        className="w-full min-w-[200px] rounded-xl sm:w-auto sm:min-w-[220px]"
                        size="lg"
                        disabled={form.processing}
                    >
                        {form.processing
                            ? 'Saving…'
                            : template
                              ? 'Save template'
                              : 'Create template'}
                    </Button>
                </div>
            </div>

            <AlertDialog open={deleteStageOpen} onOpenChange={setDeleteStageOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove this step?</AlertDialogTitle>
                        <AlertDialogDescription>
                            &quot;
                            {s?.label ?? s?.key}
                            &quot; and its field selections will be removed from this template. You can undo by discarding
                            before save.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => {
                                const nextStages = builder.stages.filter((x) => x.id !== activeStageId);
                                setBuilder({ stages: nextStages });
                                setActiveStageId(nextStages.length > 0 ? nextStages[0].id : null);
                                setConfigureTab('basics');
                                setDeleteStageOpen(false);
                                toast.success('Step removed.');
                            }}
                        >
                            Remove step
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <SortDialog
                state={sortFieldsDialog}
                setState={setSortFieldsDialog}
                getLabel={sortFieldDialogLabel}
                onSave={(kind, list) => {
                    updateStage(
                        kind === 'contract'
                            ? { contract_fields: list }
                            : kind === 'bank'
                              ? { bank_account_fields: list }
                              : kind === 'sea_service'
                                ? { sea_service_fields: list }
                                : kind === 'vaccination'
                                  ? { vaccination_fields: list }
                                  : { employee_fields: list },
                    );
                    setSortFieldsDialog({
                        open: false,
                        kind: null,
                        list: [],
                        draggingKey: null,
                    });
                    toast.success(
                        `${kind === 'contract' ? 'Contract' : kind === 'bank' ? 'Bank' : kind === 'sea_service' ? 'Sea service' : kind === 'vaccination' ? 'Vaccination' : 'Employee'} field order saved.`,
                    );
                }}
            />
        </form>
    );
}
