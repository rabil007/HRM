import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyBankAccount,
    store as storeBankAccount,
    update as updateBankAccount,
} from '@/actions/App/Http/Controllers/Organization/EmployeeBankAccountController';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CreatableSelect } from '@/components/ui/creatable-select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import type { BankOption } from '@/features/organization/employees/types';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type {
    EmployeeBankAccountItem,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const BANK_ACCOUNTS_RELOAD = {
    preserveScroll: true,
    only: ['bank_accounts', 'employee'],
} as const;

export type EmployeeBankTabProps = {
    employeeId: number | null;
    bank_accounts: EmployeeBankAccountItem[];
    banks: BankOption[];
    canManage: boolean;
    ensureEmployee?: () => Promise<number>;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeBankTab({
    employeeId,
    bank_accounts,
    banks,
    canManage,
    ensureEmployee,
    templateFields = null,
}: EmployeeBankTabProps): ReactElement {
    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_bank_accounts,
        booleanFields: ['is_primary'],
    });

    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingRow, setEditingRow] = useState<EmployeeBankAccountItem | null>(
        null,
    );
    const [deleteId, setDeleteId] = useState<number | null>(null);

    const bankForm = useForm({
        bank_id: '',
        iban: '',
        account_name: '',
        is_primary: false,
    });

    useClearMissingOnFormChange(
        bankForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    const { selectOptions: bankSelectOptions, appendOption: appendBankOption } =
        useMutableSelectOptions(banks);
    const { canCreate: canCreateBank, createConfig: bankCreateConfig } =
        useCreatableMasterData('bank');

    const openCreateDialog = () => {
        bankForm.reset();
        bankForm.clearErrors();
        clearMissingRequired();
        bankForm.setData({
            bank_id: '',
            iban: '',
            account_name: '',
            is_primary: bank_accounts.length === 0 ? true : false,
        });
        setEditingRow(null);
        setDialogOpen(true);
    };

    const openEditDialog = (row: EmployeeBankAccountItem) => {
        bankForm.clearErrors();
        clearMissingRequired();
        bankForm.setData({
            bank_id: row.bank_id ? String(row.bank_id) : '',
            iban: row.iban ?? '',
            account_name: row.account_name ?? '',
            is_primary: row.is_primary,
        });
        setEditingRow(row);
        setDialogOpen(true);
    };

    const submitBankAccount = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        if (!validateRequired(bankForm.data as Record<string, unknown>)) {
            return;
        }

        bankForm.clearErrors();
        bankForm.transform((data) => ({
            bank_id: data.bank_id ? Number(data.bank_id) : null,
            iban: data.iban?.trim() || null,
            account_name: data.account_name?.trim() || null,
            is_primary: !!data.is_primary,
        }));

        const url = editingRow
            ? updateBankAccount.url({
                  employee: resolvedEmployeeId,
                  bankAccount: editingRow.id,
              })
            : storeBankAccount.url({
                  employee: resolvedEmployeeId,
              });

        const options = {
            ...BANK_ACCOUNTS_RELOAD,
            onSuccess: () => {
                setDialogOpen(false);
                bankForm.reset();
                setEditingRow(null);
                clearMissingRequired();
            },
        };

        if (editingRow) {
            bankForm.put(url, options);
        } else {
            bankForm.post(url, options);
        }
    };

    const showAccountDetailsSection =
        showField('bank_id') ||
        showField('account_name') ||
        showField('iban');
    const showSettingsSection = showField('is_primary');

    return (
        <TabsContent value="bank" className="mt-6">
            <EmployeeRecordsPanel
                title="Bank accounts"
                count={bank_accounts.length}
                isEmpty={bank_accounts.length === 0}
                emptyMessage="No bank accounts recorded."
                actions={
                    canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateDialog}
                        >
                            + Add bank account
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[720px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('bank_id') ? (
                                <th className={employeeRecordsTableThClass()}>Bank</th>
                            ) : null}
                            {showField('account_name') ? (
                                <th className={employeeRecordsTableThClass()}>Account holder</th>
                            ) : null}
                            {showField('iban') ? (
                                <th className={employeeRecordsTableThClass()}>IBAN</th>
                            ) : null}
                            {showField('is_primary') ? (
                                <th className={cn(employeeRecordsTableThClass(), 'text-center')}>
                                    Primary
                                </th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>Added</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {bank_accounts.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                {showField('bank_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[200px] truncate font-medium text-foreground',
                                        )}
                                    >
                                        {row.bank_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('account_name') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[180px] truncate',
                                        )}
                                    >
                                        {row.account_name ?? '—'}
                                    </td>
                                ) : null}
                                {showField('iban') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[240px] truncate font-mono text-xs',
                                        )}
                                    >
                                        {row.iban ?? '—'}
                                    </td>
                                ) : null}
                                {showField('is_primary') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_primary ? (
                                            <span className="text-emerald-400">✓</span>
                                        ) : (
                                            <span className="text-muted-foreground/50">—</span>
                                        )}
                                    </td>
                                ) : null}
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-xs text-muted-foreground',
                                    )}
                                >
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <EmployeeRecordRowActions
                                            onEdit={() => openEditDialog(row)}
                                            onDelete={() => setDeleteId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
            <Dialog
                open={dialogOpen}
                onOpenChange={(openDialog) => {
                    setDialogOpen(openDialog);

                    if (!openDialog) {
                        bankForm.reset();
                        bankForm.clearErrors();
                        setEditingRow(null);
                        clearMissingRequired();
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow ? 'Edit bank account' : 'Add bank account'}
                        </DialogTitle>
                        <DialogDescription className="text-xs text-muted-foreground">
                            Enter the account details used for payroll disbursement.
                        </DialogDescription>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showAccountDetailsSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Account details
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            {showField('bank_id') ? (
                                <RecordFormField
                                    field="bank_id"
                                    highlightMissing={isMissingRequired('bank_id')}
                                >
                                    <Label className={recordFieldLabelClass(isMissingRequired('bank_id'))}>
                                        Bank
                                        <RequiredIndicator show={isFieldRequired('bank_id')} />
                                    </Label>
                                    <CreatableSelect
                                        value={bankForm.data.bank_id}
                                        onValueChange={(v) => bankForm.setData('bank_id', v)}
                                        variant="dark"
                                        placeholder="— Select a bank —"
                                        options={bankSelectOptions}
                                        onOptionsChange={(next) => {
                                            const added = next.find(
                                                (option) =>
                                                    !bankSelectOptions.some(
                                                        (existing) =>
                                                            existing.value === option.value,
                                                    ),
                                            );

                                            if (added) {
                                                appendBankOption({
                                                    id: added.id,
                                                    label: added.label,
                                                });
                                            }
                                        }}
                                        creatable
                                        canCreate={canCreateBank}
                                        createConfig={bankCreateConfig}
                                    />
                                    {bankForm.errors.bank_id ? (
                                        <p className="text-xs text-destructive">
                                            {bankForm.errors.bank_id}
                                        </p>
                                    ) : (
                                        <p className="text-[11px] text-muted-foreground">
                                            The financial institution holding this account
                                            {isFieldRequired('bank_id') ? '' : ' (optional)'}
                                        </p>
                                    )}
                                </RecordFormField>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('account_name') ? (
                                    <RecordFormField
                                        field="account_name"
                                        highlightMissing={isMissingRequired('account_name')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('account_name'),
                                            )}
                                        >
                                            Account holder
                                            <RequiredIndicator
                                                show={isFieldRequired('account_name')}
                                            />
                                        </Label>
                                        <Input
                                            className={recordFieldInputClass(
                                                isMissingRequired('account_name'),
                                            )}
                                            placeholder="e.g. John M. Doe"
                                            value={bankForm.data.account_name}
                                            onChange={(e) =>
                                                bankForm.setData('account_name', e.target.value)
                                            }
                                        />
                                        {bankForm.errors.account_name ? (
                                            <p className="text-xs text-destructive">
                                                {bankForm.errors.account_name}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                Full name as shown on the account
                                                {isFieldRequired('account_name') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('iban') ? (
                                    <RecordFormField
                                        field="iban"
                                        highlightMissing={isMissingRequired('iban')}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('iban'),
                                            )}
                                        >
                                            IBAN
                                            <RequiredIndicator show={isFieldRequired('iban')} />
                                        </Label>
                                        <Input
                                            className={cn(
                                                recordFieldInputClass(isMissingRequired('iban')),
                                                'font-mono',
                                            )}
                                            placeholder="e.g. AE07 0331 2345 6789 0123 456"
                                            value={bankForm.data.iban}
                                            onChange={(e) =>
                                                bankForm.setData('iban', e.target.value)
                                            }
                                        />
                                        {bankForm.errors.iban ? (
                                            <p className="text-xs text-destructive">
                                                {bankForm.errors.iban}
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                International bank account number
                                                {isFieldRequired('iban') ? '' : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    {showSettingsSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2 pt-1">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Settings
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <RecordFormField
                                field="is_primary"
                                highlightMissing={isMissingRequired('is_primary')}
                            >
                                <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                    <label className="flex items-center gap-3 text-sm text-foreground">
                                        <Checkbox
                                            checked={bankForm.data.is_primary}
                                            disabled={
                                                editingRow !== null &&
                                                bank_accounts.length === 1
                                            }
                                            onCheckedChange={(v) =>
                                                bankForm.setData('is_primary', v === true)
                                            }
                                        />
                                        <div>
                                            <div className="font-medium">
                                                Primary payroll account
                                                <RequiredIndicator
                                                    show={isFieldRequired('is_primary')}
                                                />
                                            </div>
                                            <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                Salary will be deposited to this account by default
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </RecordFormField>
                        </div>
                    ) : null}

                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={bankForm.processing}
                            onClick={submitBankAccount}
                        >
                            {bankForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteId(null);
                    }
                }}
                title="Remove bank account?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteId && employeeId
                        ? destroyBankAccount.url({
                              employee: employeeId,
                              bankAccount: deleteId,
                          })
                        : null
                }
                reloadOptions={BANK_ACCOUNTS_RELOAD}
            />
        </TabsContent>
    );
}
