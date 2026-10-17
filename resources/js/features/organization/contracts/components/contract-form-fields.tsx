import type { InertiaFormProps } from '@inertiajs/react';
import { Banknote, CalendarDays, FileText, MessageSquare } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ContractFormData } from '@/features/organization/contracts/lib/contract-form-data';
import {
    contractFormLiveTotal,
    isCrewContractForm,
    isCrewDailyForm,
    isCrewMonthlyForm,
} from '@/features/organization/contracts/lib/contract-form-data';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
import { cn } from '@/lib/utils';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
};

type ContractFormFieldProps = {
    field: string;
    highlightMissing: boolean;
    children: ReactNode;
};

function ContractFormField({
    field,
    highlightMissing,
    children,
}: ContractFormFieldProps): ReactElement {
    return (
        <div
            data-contract-field={field}
            className={cn(
                'space-y-1.5 rounded-xl',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
        >
            {children}
            {highlightMissing ? (
                <p className="text-xs text-rose-400">Required</p>
            ) : null}
        </div>
    );
}

function RequiredIndicator({ show }: { show: boolean }): ReactElement | null {
    if (!show) {
        return null;
    }

    return <span className="text-red-400"> *</span>;
}

function formatStatus(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return STATUS_LABELS[value] ?? value;
}

function contractStatusClass(status: string | null | undefined): string {
    if (status === 'active') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
    }

    if (status === 'draft') {
        return 'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-300';
    }

    return 'border-zinc-500/25 bg-zinc-500/10 text-muted-foreground';
}

export function CurrencyInput({
    id,
    value,
    onChange,
    placeholder,
    className,
    disabled = false,
}: {
    id: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    className?: string;
    disabled?: boolean;
}): ReactElement {
    return (
        <div className="relative">
            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-[11px] font-medium text-muted-foreground select-none">
                AED
            </span>
            <Input
                id={id}
                inputMode="decimal"
                placeholder={placeholder}
                disabled={disabled}
                className={cn(
                    'h-10 rounded-xl border-border/60 bg-muted/50 pl-10 text-sm tabular-nums',
                    className,
                )}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
}

export type ContractFormFieldsProps = {
    form: InertiaFormProps<ContractFormData>;
    showField: (field: string) => boolean;
    isFieldRequired: (field: string) => boolean;
    isMissingRequired: (field: string) => boolean;
    companyVisaTypes: CompanyVisaTypeOption[];
    compensationLocked?: boolean;
    idPrefix?: string;
};

export function ContractFormFields({
    form,
    showField,
    isFieldRequired,
    isMissingRequired,
    companyVisaTypes,
    compensationLocked = false,
    idPrefix = 'contract',
}: ContractFormFieldsProps): ReactElement {
    const isCrewContract = isCrewContractForm(form.data);
    const isCrewMonthly = isCrewMonthlyForm(form.data);
    const isCrewDaily = isCrewDailyForm(form.data);

    const showContractDetailsSection =
        showField('payroll_category') ||
        showField('salary_structure') ||
        showField('status') ||
        showField('labor_contract_id') ||
        showField('company_visa_type_id');
    const showDurationSection =
        showField('start_date') || showField('end_date');
    const showCompensationSection =
        showField('basic_salary') ||
        showField('housing_allowance') ||
        showField('transport_allowance') ||
        showField('other_allowances') ||
        showField('eos_monthly_amount') ||
        showField('supplementary_allowance') ||
        showField('site_allowance');
    const showNoteSection = showField('note');

    const liveTotal = contractFormLiveTotal(form.data, {
        includeEos: isCrewMonthly && showField('eos_monthly_amount'),
    });

    return (
        <div className="space-y-4">
            {showContractDetailsSection ? (
                <div className="space-y-4 py-1">
                    <div className="flex items-center gap-2">
                        <FileText
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                            Contract details
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {showField('payroll_category') ? (
                            <ContractFormField
                                field="payroll_category"
                                highlightMissing={isMissingRequired(
                                    'payroll_category',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_payroll_category`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('payroll_category') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Payroll category
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'payroll_category',
                                        )}
                                    />
                                </Label>
                                <AppSelect
                                    value={form.data.payroll_category}
                                    disabled={compensationLocked}
                                    onValueChange={(v) =>
                                        form.setData((data) => ({
                                            ...data,
                                            payroll_category: v,
                                            salary_structure:
                                                v === 'crew'
                                                    ? 'daily'
                                                    : 'monthly',
                                        }))
                                    }
                                    variant="dark"
                                >
                                    <AppSelectItem value="office">
                                        Office
                                    </AppSelectItem>
                                    <AppSelectItem value="crew">
                                        Crew
                                    </AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-muted-foreground">
                                    Office uses monthly salary; crew uses daily
                                    or monthly structures with timesheets
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('salary_structure') && isCrewContract ? (
                            <ContractFormField
                                field="salary_structure"
                                highlightMissing={isMissingRequired(
                                    'salary_structure',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_salary_structure`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('salary_structure') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Salary structure
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'salary_structure',
                                        )}
                                    />
                                </Label>
                                <AppSelect
                                    value={form.data.salary_structure}
                                    disabled={compensationLocked}
                                    onValueChange={(v) =>
                                        form.setData('salary_structure', v)
                                    }
                                    variant="dark"
                                >
                                    <AppSelectItem value="daily">
                                        Daily
                                    </AppSelectItem>
                                    <AppSelectItem value="monthly">
                                        Monthly
                                    </AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-muted-foreground">
                                    Daily crew uses site and supplementary
                                    rates; monthly crew uses housing, transport,
                                    and other allowances
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('status') ? (
                            <ContractFormField
                                field="status"
                                highlightMissing={isMissingRequired('status')}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_status`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('status') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Status
                                    <RequiredIndicator
                                        show={isFieldRequired('status')}
                                    />
                                </Label>
                                <div className="flex items-center gap-2">
                                    <AppSelect
                                        value={form.data.status}
                                        onValueChange={(v) =>
                                            form.setData('status', v)
                                        }
                                        variant="dark"
                                    >
                                        <AppSelectItem value="active">
                                            Active
                                        </AppSelectItem>
                                        <AppSelectItem value="ended">
                                            Ended
                                        </AppSelectItem>
                                    </AppSelect>
                                    <span
                                        className={cn(
                                            'inline-flex shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium',
                                            contractStatusClass(
                                                form.data.status,
                                            ),
                                        )}
                                    >
                                        {formatStatus(form.data.status)}
                                    </span>
                                </div>
                                <p className="text-[11px] text-muted-foreground">
                                    Current state of this contract
                                </p>
                            </ContractFormField>
                        ) : null}
                    </div>
                    {showField('labor_contract_id') ? (
                        <ContractFormField
                            field="labor_contract_id"
                            highlightMissing={isMissingRequired(
                                'labor_contract_id',
                            )}
                        >
                            <Label
                                htmlFor={`${idPrefix}_labor_contract_id`}
                                className={cn(
                                    'text-xs',
                                    isMissingRequired('labor_contract_id') &&
                                        employeeFieldMissingLabelClass,
                                )}
                            >
                                Labor contract ID
                                <RequiredIndicator
                                    show={isFieldRequired('labor_contract_id')}
                                />
                            </Label>
                            <Input
                                id={`${idPrefix}_labor_contract_id`}
                                className={cn(
                                    'h-10 rounded-xl border-border/60 bg-muted/50 font-mono text-sm tracking-wide',
                                    isMissingRequired('labor_contract_id') &&
                                        'border-rose-500/50',
                                )}
                                placeholder="e.g. MOL-2024-00123"
                                value={form.data.labor_contract_id}
                                onChange={(e) =>
                                    form.setData(
                                        'labor_contract_id',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-[11px] text-muted-foreground">
                                Reference number from the labor authority
                                {isFieldRequired('labor_contract_id')
                                    ? ''
                                    : ' (optional)'}
                            </p>
                        </ContractFormField>
                    ) : null}
                    {showField('company_visa_type_id') ? (
                        <ContractFormField
                            field="company_visa_type_id"
                            highlightMissing={isMissingRequired(
                                'company_visa_type_id',
                            )}
                        >
                            <Label
                                htmlFor={`${idPrefix}_company_visa_type_id`}
                                className={cn(
                                    'text-xs',
                                    isMissingRequired('company_visa_type_id') &&
                                        employeeFieldMissingLabelClass,
                                )}
                            >
                                Sponsor
                                <RequiredIndicator
                                    show={isFieldRequired(
                                        'company_visa_type_id',
                                    )}
                                />
                            </Label>
                            <AppSelect
                                value={form.data.company_visa_type_id}
                                onValueChange={(v) =>
                                    form.setData('company_visa_type_id', v)
                                }
                                variant="dark"
                                placeholder="Not set"
                            >
                                <AppSelectItem value="">Not set</AppSelectItem>
                                {companyVisaTypes.map((option) => (
                                    <AppSelectItem
                                        key={option.id}
                                        value={String(option.id)}
                                    >
                                        {option.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <p className="text-[11px] text-muted-foreground">
                                The sponsor that applies during this contract's
                                effective period
                                {isFieldRequired('company_visa_type_id')
                                    ? ''
                                    : ' (optional)'}
                            </p>
                        </ContractFormField>
                    ) : null}
                </div>
            ) : null}

            {showDurationSection ? (
                <div className="space-y-4 pt-2">
                    <div className="flex items-center gap-2">
                        <CalendarDays
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                            Duration
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {showField('start_date') ? (
                            <ContractFormField
                                field="start_date"
                                highlightMissing={isMissingRequired(
                                    'start_date',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_start_date`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('start_date') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Start date
                                    <RequiredIndicator
                                        show={isFieldRequired('start_date')}
                                    />
                                </Label>
                                <Input
                                    id={`${idPrefix}_start_date`}
                                    type="date"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('start_date') &&
                                            'border-rose-500/50',
                                    )}
                                    value={form.data.start_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'start_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.start_date ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.start_date}
                                    </p>
                                ) : (
                                    <p className="text-[11px] text-muted-foreground">
                                        When the contract becomes effective
                                    </p>
                                )}
                            </ContractFormField>
                        ) : null}
                        {showField('end_date') ? (
                            <ContractFormField
                                field="end_date"
                                highlightMissing={isMissingRequired('end_date')}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_end_date`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('end_date') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    End date
                                    <RequiredIndicator
                                        show={isFieldRequired('end_date')}
                                    />
                                </Label>
                                <Input
                                    id={`${idPrefix}_end_date`}
                                    type="date"
                                    className={cn(
                                        'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        isMissingRequired('end_date') &&
                                            'border-rose-500/50',
                                    )}
                                    value={form.data.end_date}
                                    onChange={(e) =>
                                        form.setData('end_date', e.target.value)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Leave blank for unlimited contracts
                                    {isFieldRequired('end_date')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {showCompensationSection ? (
                <div className="space-y-4 pt-2">
                    <div className="flex items-center gap-2">
                        <Banknote
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                            Compensation
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    {compensationLocked ? (
                        <p className="text-xs text-muted-foreground">
                            Compensation cannot be changed here. Use salary
                            revisions to update pay rates.
                        </p>
                    ) : null}
                    {isCrewMonthly &&
                    [
                        'housing_allowance',
                        'transport_allowance',
                        'other_allowances',
                        'eos_monthly_amount',
                    ].some((field) => !showField(field)) ? (
                        <p className="text-xs text-amber-600 sm:col-span-2">
                            Some monthly compensation fields are hidden by this
                            employee's profile template. Enable them in the
                            Contract fields of the employee profile template to
                            edit their values.
                        </p>
                    ) : null}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {showField('basic_salary') ? (
                            <ContractFormField
                                field="basic_salary"
                                highlightMissing={isMissingRequired(
                                    'basic_salary',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_basic_salary`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('basic_salary') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Basic salary
                                    <RequiredIndicator
                                        show={isFieldRequired('basic_salary')}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_basic_salary`}
                                    placeholder="5,000.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired('basic_salary') &&
                                            'border-rose-500/50',
                                    )}
                                    value={form.data.basic_salary}
                                    onChange={(v) =>
                                        form.setData('basic_salary', v)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    {isCrewDaily
                                        ? 'Daily basic rate for crew payroll'
                                        : 'Monthly base salary'}
                                    {isFieldRequired('basic_salary')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('housing_allowance') &&
                        (!isCrewContract || isCrewMonthly) ? (
                            <ContractFormField
                                field="housing_allowance"
                                highlightMissing={isMissingRequired(
                                    'housing_allowance',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_housing_allowance`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired(
                                            'housing_allowance',
                                        ) && employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Housing allowance
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'housing_allowance',
                                        )}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_housing_allowance`}
                                    placeholder="1,500.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired(
                                            'housing_allowance',
                                        ) && 'border-rose-500/50',
                                    )}
                                    value={form.data.housing_allowance}
                                    onChange={(v) =>
                                        form.setData('housing_allowance', v)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Monthly housing benefit
                                    {isFieldRequired('housing_allowance')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('transport_allowance') &&
                        (!isCrewContract || isCrewMonthly) ? (
                            <ContractFormField
                                field="transport_allowance"
                                highlightMissing={isMissingRequired(
                                    'transport_allowance',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_transport_allowance`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired(
                                            'transport_allowance',
                                        ) && employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Transport allowance
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'transport_allowance',
                                        )}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_transport_allowance`}
                                    placeholder="500.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired(
                                            'transport_allowance',
                                        ) && 'border-rose-500/50',
                                    )}
                                    value={form.data.transport_allowance}
                                    onChange={(v) =>
                                        form.setData('transport_allowance', v)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Monthly transport benefit
                                    {isFieldRequired('transport_allowance')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('other_allowances') &&
                        (!isCrewContract || isCrewMonthly) ? (
                            <ContractFormField
                                field="other_allowances"
                                highlightMissing={isMissingRequired(
                                    'other_allowances',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_other_allowances`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('other_allowances') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Other allowances
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'other_allowances',
                                        )}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_other_allowances`}
                                    placeholder="200.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired('other_allowances') &&
                                            'border-rose-500/50',
                                    )}
                                    value={form.data.other_allowances}
                                    onChange={(v) =>
                                        form.setData('other_allowances', v)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Any additional monthly allowances
                                    {isFieldRequired('other_allowances')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                        {isCrewMonthly && showField('eos_monthly_amount') ? (
                            <ContractFormField
                                field="eos_monthly_amount"
                                highlightMissing={isMissingRequired(
                                    'eos_monthly_amount',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_eos_monthly_amount`}
                                    className="text-xs"
                                >
                                    EOS
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_eos_monthly_amount`}
                                    disabled={compensationLocked}
                                    value={form.data.eos_monthly_amount}
                                    onChange={(v) =>
                                        form.setData('eos_monthly_amount', v)
                                    }
                                />
                                {form.errors.eos_monthly_amount ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.eos_monthly_amount}
                                    </p>
                                ) : null}
                            </ContractFormField>
                        ) : null}
                        {showField('supplementary_allowance') &&
                        (!isCrewContract || isCrewDaily) ? (
                            <ContractFormField
                                field="supplementary_allowance"
                                highlightMissing={isMissingRequired(
                                    'supplementary_allowance',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_supplementary_allowance`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired(
                                            'supplementary_allowance',
                                        ) && employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Supplementary allowance
                                    <RequiredIndicator
                                        show={isFieldRequired(
                                            'supplementary_allowance',
                                        )}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_supplementary_allowance`}
                                    placeholder="428.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired(
                                            'supplementary_allowance',
                                        ) && 'border-rose-500/50',
                                    )}
                                    value={form.data.supplementary_allowance}
                                    onChange={(v) =>
                                        form.setData(
                                            'supplementary_allowance',
                                            v,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Daily supplementary rate (crewing)
                                    {isFieldRequired('supplementary_allowance')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                        {showField('site_allowance') &&
                        (!isCrewContract || isCrewDaily) ? (
                            <ContractFormField
                                field="site_allowance"
                                highlightMissing={isMissingRequired(
                                    'site_allowance',
                                )}
                            >
                                <Label
                                    htmlFor={`${idPrefix}_site_allowance`}
                                    className={cn(
                                        'text-xs',
                                        isMissingRequired('site_allowance') &&
                                            employeeFieldMissingLabelClass,
                                    )}
                                >
                                    Site allowance
                                    <RequiredIndicator
                                        show={isFieldRequired('site_allowance')}
                                    />
                                </Label>
                                <CurrencyInput
                                    id={`${idPrefix}_site_allowance`}
                                    placeholder="715.00"
                                    disabled={compensationLocked}
                                    className={cn(
                                        isMissingRequired('site_allowance') &&
                                            'border-rose-500/50',
                                    )}
                                    value={form.data.site_allowance}
                                    onChange={(v) =>
                                        form.setData('site_allowance', v)
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Daily site rate (on-site days)
                                    {isFieldRequired('site_allowance')
                                        ? ''
                                        : ' (optional)'}
                                </p>
                            </ContractFormField>
                        ) : null}
                    </div>

                    {liveTotal.hasAnyValue ? (
                        <div className="flex items-center justify-between rounded-xl border border-border/60 bg-muted/30 px-4 py-2.5">
                            <span className="text-xs text-muted-foreground">
                                {isCrewDaily
                                    ? 'Total daily rate'
                                    : 'Total monthly salary'}
                            </span>
                            <span className="font-mono text-sm font-semibold tabular-nums">
                                AED{' '}
                                {liveTotal.total.toLocaleString(undefined, {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2,
                                })}
                            </span>
                        </div>
                    ) : (
                        <p className="text-[11px] text-muted-foreground">
                            Enter at least one compensation value to calculate
                            the total.
                        </p>
                    )}
                </div>
            ) : null}

            {showNoteSection ? (
                <div className="space-y-4 pt-2">
                    <div className="flex items-center gap-2">
                        <MessageSquare
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                            Note
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <ContractFormField
                        field="note"
                        highlightMissing={isMissingRequired('note')}
                    >
                        <Label
                            htmlFor={`${idPrefix}_note`}
                            className={cn(
                                'text-xs',
                                isMissingRequired('note') &&
                                    employeeFieldMissingLabelClass,
                            )}
                        >
                            Note
                            <RequiredIndicator show={isFieldRequired('note')} />
                        </Label>
                        <Textarea
                            id={`${idPrefix}_note`}
                            rows={3}
                            placeholder="Reason for this contract or contract change…"
                            className={cn(
                                'min-h-[88px] resize-y rounded-xl border-border/60 bg-muted/50 text-sm',
                                isMissingRequired('note') &&
                                    'border-rose-500/50',
                            )}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                        {form.errors.note ? (
                            <p className="text-xs text-destructive">
                                {form.errors.note}
                            </p>
                        ) : (
                            <p className="text-[11px] text-muted-foreground">
                                Context for new contracts or amendments
                                {isFieldRequired('note') ? '' : ' (optional)'}
                            </p>
                        )}
                    </ContractFormField>
                </div>
            ) : null}
        </div>
    );
}
