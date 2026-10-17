import { useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { ContractFormFields } from '@/features/organization/contracts/components/contract-form-fields';
import {
    buildContractPayload,
    emptyContractFormData,
    hydrateContractFormData,
} from '@/features/organization/contracts/lib/contract-form-data';
import type { ContractEmployeeSummary } from '@/features/organization/contracts/types';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import { applyRecordFormErrors } from '@/pages/organization/_lib/apply-record-form-errors';
import {
    createTemplateFieldVisibility,
    getTemplateRequiredFieldKeys,
    isEmptyTemplateFieldValue,
    isTemplateFieldRequired,
} from '@/pages/organization/_lib/template-field-visibility';
import type {
    EmployeeContractDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';
import {
    store as storeContract,
    update as updateContract,
} from '@/actions/App/Http/Controllers/Organization/EmployeeContractController';

const DEFAULT_CONTRACT_REQUIRED_FIELDS = ['start_date', 'status'] as const;

export type ContractFormSheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: 'create' | 'edit';
    employee: ContractEmployeeSummary | null;
    editingContract: EmployeeContractDetails | null;
    templateContractFields?: Record<string, TemplateFieldConfig> | null;
    companyVisaTypes?: CompanyVisaTypeOption[];
    canCreate: boolean;
    canUpdate: boolean;
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    onSuccess?: () => void;
    reloadOnly?: string[];
};

export function ContractFormSheet({
    open,
    onOpenChange,
    mode,
    employee,
    editingContract,
    templateContractFields = null,
    companyVisaTypes = [],
    canCreate,
    canUpdate,
    employeeId,
    ensureEmployee,
    onSuccess,
    reloadOnly = ['contracts', 'employee', 'summary', 'pagination'],
}: ContractFormSheetProps): ReactElement {
    const form = useForm(emptyContractFormData());
    const [missingRequiredFields, setMissingRequiredFields] = useState<
        Set<string>
    >(() => new Set());
    const hydratedKeyRef = useRef<string | null>(null);

    const showField = useMemo(
        () => createTemplateFieldVisibility(templateContractFields),
        [templateContractFields],
    );

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(templateContractFields, [
                ...DEFAULT_CONTRACT_REQUIRED_FIELDS,
            ]),
        [templateContractFields],
    );

    const isFieldRequired = useCallback(
        (fieldKey: string) =>
            isTemplateFieldRequired(templateContractFields, fieldKey, [
                ...DEFAULT_CONTRACT_REQUIRED_FIELDS,
            ]),
        [templateContractFields],
    );

    const isMissingRequired = useCallback(
        (field: string) => missingRequiredFields.has(field),
        [missingRequiredFields],
    );

    const missingRequiredFieldsList = useMemo(
        () => Array.from(missingRequiredFields),
        [missingRequiredFields],
    );

    const focusMissingField = useCallback((field: string) => {
        requestAnimationFrame(() => {
            document
                .querySelector(`[data-contract-field="${field}"]`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }, []);

    const canSubmit =
        mode === 'edit' ? canUpdate && Boolean(editingContract) : canCreate;

    useEffect(() => {
        if (!open) {
            hydratedKeyRef.current = null;

            return;
        }

        const key =
            mode === 'edit' && editingContract
                ? `edit:${editingContract.id}`
                : `create:${employee?.id ?? employeeId ?? 'new'}`;

        if (hydratedKeyRef.current === key) {
            return;
        }

        hydratedKeyRef.current = key;
        form.clearErrors();
        setMissingRequiredFields(new Set());

        if (mode === 'edit' && editingContract) {
            form.setData(hydrateContractFormData(editingContract));
        } else {
            form.setData(
                emptyContractFormData({
                    companyVisaTypeId: employee?.company_visa_type_id ?? null,
                }),
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- hydrate once per open/mode/contract
    }, [
        open,
        mode,
        editingContract,
        employee?.id,
        employee?.company_visa_type_id,
        employeeId,
    ]);

    useEffect(() => {
        if (missingRequiredFields.size === 0) {
            return;
        }

        setMissingRequiredFields((current) => {
            const next = new Set(current);
            let changed = false;

            for (const field of current) {
                const value = form.data[field as keyof typeof form.data];

                if (!isEmptyTemplateFieldValue(value)) {
                    next.delete(field);
                    changed = true;
                }
            }

            return changed ? next : current;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- clear highlights when field values change
    }, [form.data]);

    const submitContract = useCallback(async () => {
        if (!canSubmit) {
            return;
        }

        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId ?? employee?.id ?? null,
                ensureEmployee,
            );
        } catch {
            return;
        }

        const missing: string[] = [];

        for (const field of requiredFields) {
            if (!showField(field)) {
                continue;
            }

            const value = form.data[field as keyof typeof form.data];

            if (isEmptyTemplateFieldValue(value)) {
                missing.push(field);
            }
        }

        if (missing.length > 0) {
            setMissingRequiredFields(new Set(missing));
            toast.error(
                'Please fill the highlighted required fields before saving.',
            );
            focusMissingField(missing[0]);

            return;
        }

        setMissingRequiredFields(new Set());
        form.clearErrors();

        const payload = buildContractPayload(form.data, templateContractFields);

        const url =
            mode === 'edit' && editingContract
                ? updateContract.url({
                      employee: resolvedEmployeeId,
                      employeeContract: editingContract.id,
                  })
                : storeContract.url({ employee: resolvedEmployeeId });

        const options = {
            preserveScroll: true,
            only: reloadOnly,
            onSuccess: () => {
                onOpenChange(false);
                form.reset();
                setMissingRequiredFields(new Set());
                onSuccess?.();
            },
            onError: (errors: Record<string, string | string[]>) => {
                applyRecordFormErrors(form, errors);
            },
        };

        if (mode === 'edit' && editingContract) {
            form.transform(() => payload as never);
            form.put(url, {
                ...options,
                onFinish: () => {
                    form.transform((data) => data);
                },
            });
        } else {
            form.transform(() => payload as never);
            form.post(url, {
                ...options,
                onFinish: () => {
                    form.transform((data) => data);
                },
            });
        }
    }, [
        canSubmit,
        editingContract,
        employee?.id,
        employeeId,
        ensureEmployee,
        focusMissingField,
        form,
        mode,
        onOpenChange,
        onSuccess,
        reloadOnly,
        requiredFields,
        showField,
        templateContractFields,
    ]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const handleKeyDown = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                void submitContract();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, submitContract]);

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            form.reset();
            form.clearErrors();
            setMissingRequiredFields(new Set());
            hydratedKeyRef.current = null;
        }

        onOpenChange(nextOpen);
    };

    const employeeLabel = employee
        ? `${employee.name}${employee.employee_no ? ` · ${employee.employee_no}` : ''}`
        : null;

    return (
        <Sheet open={open} onOpenChange={handleOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-2xl"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {mode === 'edit' ? 'Edit contract' : 'Add contract'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {employeeLabel
                            ? employeeLabel
                            : 'Fill in the contract details. Fields marked required in your profile template must be completed.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    <ContractFormFields
                        form={form}
                        showField={showField}
                        isFieldRequired={isFieldRequired}
                        isMissingRequired={isMissingRequired}
                        companyVisaTypes={companyVisaTypes}
                        compensationLocked={mode === 'edit'}
                    />
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => handleOpenChange(false)}
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className="h-11 flex-1 rounded-xl px-8 font-semibold"
                        disabled={!canSubmit || form.processing}
                        onClick={() => void submitContract()}
                    >
                        {form.processing ? 'Saving…' : 'Save'}
                        <span className="ml-2 hidden text-[10px] font-normal opacity-70 sm:inline">
                            ⌘↵
                        </span>
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
